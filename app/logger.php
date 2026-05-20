<?php
declare(strict_types=1);

function log_insert(PDO $db, array $row): int
{
    $sql = 'INSERT INTO email_log
        (source_app, template, from_email, to_email, reply_to_email, subject,
         status, request_json, remote_ip)
        VALUES
        (:source_app, :template, :from_email, :to_email, :reply_to_email,
         :subject, :status, :request_json, :remote_ip)';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':source_app'     => $row['source_app'],
        ':template'       => $row['template'],
        ':from_email'     => $row['from_email'],
        ':to_email'       => $row['to_email'],
        ':reply_to_email' => $row['reply_to_email'] ?? null,
        ':subject'        => $row['subject'],
        ':status'         => $row['status'] ?? 'queued',
        ':request_json'   => $row['request_json'] ?? null,
        ':remote_ip'      => $row['remote_ip'] ?? null,
    ]);
    return (int)$db->lastInsertId();
}

function log_update(PDO $db, int $id, array $fields): void
{
    if (!$fields) {
        return;
    }
    $sets = [];
    $params = [':id' => $id];
    foreach ($fields as $col => $val) {
        $sets[] = "$col = :$col";
        $params[":$col"] = $val;
    }
    $sql = 'UPDATE email_log SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $db->prepare($sql)->execute($params);
}
