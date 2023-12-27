<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$inputFile = fopen('Clients.csv', 'r');
$headers = fgetcsv($inputFile);

while (($row = fgetcsv($inputFile)) !== false) {
    $data = array_combine($headers, $row);

    // Inserting into the 'registrar' table
    $sql = "INSERT INTO registrar (name, clid, pw, prefix, email, whois_server, rdap_server, url, abuse_email, abuse_phone, accountBalance, creditLimit, creditThreshold, thresholdType, currency, crdate, lastupdate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['Name'], $data['Login'], 'example_pw', 'EX', $data['E-mail'], 'whois.example.com', 'rdap.example.com', 'http://example.com', 'abuse@example.com', '0', 1000.00, 0.00, 0.00, 'fixed', 'USD']);

    $registrarId = $pdo->lastInsertId();

    // Inserting into the 'registrar_contact' table
    $contactSql = "INSERT INTO registrar_contact (registrar_id, type, first_name, last_name, city, cc, voice, email) VALUES (?, 'owner', ?, ?, ?, ?, ?, ?)";
    $contactStmt = $pdo->prepare($contactSql);
    $contactStmt->execute([$registrarId, $data['Name'], $data['Name'], $data['Address'], $data['Country'], $data['Phone'], $data['E-mail']]);
    
    $contactSql = "INSERT INTO registrar_contact (registrar_id, type, first_name, last_name, city, cc, voice, email) VALUES (?, 'billing', ?, ?, ?, ?, ?, ?)";
    $contactStmt = $pdo->prepare($contactSql);
    $contactStmt->execute([$registrarId, $data['Billing Contact'], $data['Billing Contact'], $data['Address'], $data['Country'], $data['Phone'], $data['Billing E-mail']]);
    
    $contactSql = "INSERT INTO registrar_contact (registrar_id, type, first_name, last_name, city, cc, voice, email) VALUES (?, 'tech', ?, ?, ?, ?, ?, ?)";
    $contactStmt = $pdo->prepare($contactSql);
    $contactStmt->execute([$registrarId, $data['Technical Contact'], $data['Technical Contact'], $data['Address'], $data['Country'], $data['Phone'], $data['Technical E-mail']]);
}

fclose($inputFile);