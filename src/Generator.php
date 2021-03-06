<?php

declare(strict_types=1);

namespace PKP\OSF;

use SimpleXMLElement;
use SplFileInfo;

/**
 * Generates some pre/pos import scripts for SQL and also Apache redirections
 */
class Generator
{
    public static function users(SimpleXMLElement $root, Settings $settings): \Generator
    {
        foreach ($root->publication->authors->author ?? [] as $author) {
            $name = self::escape((string) $author->givenname);
            $context = self::escape($settings->context);
            $surname = self::escape((string) $author->familyname);
            $username = self::escape(strtok((string) $author->email, '@'));
            $password = self::escape(password_hash($username . sha1(uniqid()), PASSWORD_BCRYPT));
            $email = self::escape((string) $author->email);
            $authorRoleId = 0x00010000;
            yield "
            SET @exists = EXISTS(SELECT 0 FROM users WHERE username = ${username});

            INSERT INTO users (username, password, email, date_registered, date_last_login, must_change_password, inline_help)
            SELECT ${username}, ${password}, ${email}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1, 1
            WHERE @exists = 0;

            INSERT INTO user_settings (user_id, locale, setting_name, assoc_type, assoc_id, setting_value, setting_type)
            SELECT (SELECT MAX(user_id) FROM users), 'en_US', 'givenName', '0', '0', ${name}, 'string'
            WHERE @exists = 0;

            INSERT INTO user_settings (user_id, locale, setting_name, assoc_type, assoc_id, setting_value, setting_type)
            SELECT (SELECT MAX(user_id) FROM users), 'en_US', 'familyName', '0', '0', ${surname}, 'string'
            WHERE @exists = 0;

            INSERT INTO user_user_groups (user_group_id, user_id)
            SELECT (
                SELECT user_group_id 
                FROM user_groups
                WHERE role_id = ${authorRoleId}
                AND context_id = (SELECT journal_id FROM journals WHERE path = ${context})
            ), (SELECT MAX(user_id) FROM users)
            WHERE @exists = 0;";
        }
    }

    public static function linkUsers(object $preprint): string
    {
        $preprintId = self::escape((string) $preprint->id);
        $authorRoleId = 0x00010000;
        return "
        INSERT INTO stage_assignments (submission_id, user_group_id, user_id, date_assigned, can_change_metadata)
        SELECT DISTINCT p.submission_id, (
            SELECT user_group_id
            FROM user_groups
            WHERE role_id = ${authorRoleId}
            AND context_id = s.context_id
        ), u.user_id, CURRENT_TIMESTAMP, 1
        FROM publication_settings ps
        INNER JOIN publications p USING (publication_id)
        INNER JOIN submissions s USING (submission_id)
        INNER JOIN authors a USING (publication_id)
        INNER JOIN users u ON u.username = LEFT(a.email, LOCATE('@', a.email) - 1)
        WHERE
            ps.setting_value = ${preprintId}
            AND ps.setting_name = 'pub-id::publisher-id'
        ORDER BY a.seq;";
    }

    public static function downloadStatistics(object $preprint, Template $template): \Generator
    {
        if (!count($files = $template->getAllFiles())) {
            return;
        }

        $preprintId = self::escape((string) $preprint->id);
        $month = date('Ym');
        $day = date('Ymd');
        $types = [];
        $downloads = 0;
        foreach ($files as $current => $versions) {
            $file = end($versions);
            $downloads = $file->attributes->downloads;
            switch (strtoupper((new SplFileInfo($file->attributes->name))->getExtension())) {
                case 'doc':
                case 'docx':
                    $types[] = MetricsFileType::DOC;
                    break;
                case 'pdf':
                    $types[] = MetricsFileType::PDF;
                    break;
                default:
                    $types[] = MetricsFileType::OTHER;
                    break;
            }
        }
        $submissionFileType = 0x0000203;
        foreach ($types as $fileType) {
            yield "
            INSERT INTO metrics (
                load_id, context_id, pkp_section_id, submission_id, representation_id,
                assoc_type, assoc_id, day, month, file_type, metric_type, metric
            )
            SELECT 'osf-import.txt', s.context_id, s.context_id, p.submission_id, pg.galley_id, ${submissionFileType}, pg.submission_file_id, ${day}, ${month}, ${fileType}, 'ops::counter', ${downloads}
            FROM publications p
            INNER JOIN submissions s USING (submission_id)
            INNER JOIN publication_galleys pg USING (publication_id)
            WHERE
                p.publication_id = (
                    SELECT MAX(ps.publication_id)
                    FROM publication_settings ps
                    WHERE
                        ps.setting_value = ${preprintId}
                        AND ps.setting_name = 'pub-id::publisher-id'
                )
                AND pg.remote_url IS NULL
            ORDER BY p.submission_id, pg.galley_id
            LIMIT ${current}, 1;";
        }
    }

    public static function importCommand(object $preprint, SimpleXMLElement $root, Settings $settings): string
    {
        $path = realpath("{$settings->output}/submissions/{$preprint->id}/submission.xml");
        $context = $settings->context;
        foreach ($root->publication->authors->author ?? [] as $author) {
            $user = strtok((string) $author->email, '@');
            return "php tools/importExport.php NativeImportExportPlugin import ${path} ${context} ${user}";
        }
        return '';
    }

    public static function redirection(object $preprint, string $baseUrl): string
    {
        $preprintId = $preprint->id;
        $escapedPreprintId = self::escape($preprint->id);
        $baseUrl = rtrim($baseUrl, '/');
        return "
            SELECT CONCAT('Redirect permanent /${preprintId} ${baseUrl}/', (
                SELECT p.submission_id
                FROM publication_settings ps
                INNER JOIN publications p USING (publication_id)
                WHERE
                    ps.setting_value = ${escapedPreprintId}
                    AND ps.setting_name = 'pub-id::publisher-id'
                LIMIT 1
            ))
            UNION ALL";
    }

    public static function publicationRelation(object $preprint): ?string
    {
        $doi = $preprint->attributes->doi ?? null;
        if (!$doi) {
            return null;
        }

        $escapedDoi = self::escape("https://doi.org/${doi}");
        $escapedPreprintId = self::escape($preprint->id);
        $publicationRelation = DefaultValues::PUBLICATION_RELATION;
        return "
            INSERT INTO publication_settings (publication_id, locale, setting_name, setting_value)
            SELECT ps.publication_id, '', 'vorDoi', ${escapedDoi}
            FROM publication_settings ps
            WHERE
                ps.setting_value = ${escapedPreprintId}
                AND ps.setting_name = 'pub-id::publisher-id'

            UNION ALL

            SELECT ps.publication_id, '', 'relationStatus', '${publicationRelation}'
            FROM publication_settings ps
            WHERE
                ps.setting_value = ${escapedPreprintId}
                AND ps.setting_name = 'pub-id::publisher-id';";
    }

    private static function escape(string $data): string
    {
        return "'" . addcslashes($data, "\\'\0") . "'";
    }
}
