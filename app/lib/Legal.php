<?php
declare(strict_types=1);

/** Versioned legal documents (Terms, Privacy Notice, GPS consent) and consent records. */
final class Legal
{
    private static array $cache = [];

    public static function current(string $type): ?array
    {
        if (!array_key_exists($type, self::$cache)) {
            self::$cache[$type] = DB::one('SELECT * FROM legal_documents WHERE doc_type = ? AND is_current = 1 ORDER BY id DESC LIMIT 1', [$type]);
        }
        return self::$cache[$type];
    }

    public static function record(int $userId, string $consentType, ?array $doc, string $version, ?string $context = null, bool $granted = true): void
    {
        DB::insert('consents', [
            'user_id' => $userId, 'consent_type' => $consentType, 'legal_document_id' => $doc['id'] ?? null,
            'version' => $version, 'context' => $context, 'granted' => $granted ? 1 : 0,
            'ip_address' => client_ip(), 'user_agent' => user_agent(), 'accepted_at' => now(),
        ]);
    }

    /** Has the user accepted the CURRENT version of a document type? */
    public static function hasAcceptedCurrent(int $userId, string $type): bool
    {
        $doc = self::current($type);
        if (!$doc) {
            return true;
        }
        return (bool) DB::val(
            'SELECT 1 FROM consents WHERE user_id = ? AND consent_type = ? AND version = ? AND granted = 1 LIMIT 1',
            [$userId, $type, $doc['version']]
        );
    }

    /** Publish a new version (old versions are kept for history, never overwritten). */
    public static function publish(string $type, string $version, string $title, string $content, int $adminId): int
    {
        DB::begin();
        try {
            $old = self::current($type);
            DB::run('UPDATE legal_documents SET is_current = 0 WHERE doc_type = ?', [$type]);
            $id = DB::insert('legal_documents', [
                'doc_type' => $type, 'version' => $version, 'title' => $title, 'content' => $content,
                'is_current' => 1, 'published_by' => $adminId, 'created_at' => now(),
            ]);
            Audit::log('legal_document_published', 'legal_document', $id, $old ? ['version' => $old['version']] : null, ['type' => $type, 'version' => $version, 'title' => $title]);
            DB::commit();
            unset(self::$cache[$type]);
            return $id;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
