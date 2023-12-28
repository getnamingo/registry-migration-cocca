<?php

require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$logFilePath = '/var/log/namingo/import.log';
$log = setupLogger($logFilePath, 'Registry_Import_CoCCA');
$log->info('job started.');

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

try {
    $inputClients = fopen('Clients.csv', 'r');

    if (!$inputClients) {
        $log->error("Error: Unable to open file 'Clients.csv'.");
    }

    $headers = fgetcsv($inputClients);

    if (!$headers) {
        fclose($inputClients);  // Close the file
        $log->error("Error: Unable to read headers from 'Clients.csv'.");
    }

    $log->info('Starting registrar import.');
    while (($row = fgetcsv($inputClients)) !== false) {
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

    fclose($inputClients);
    
    $inputFile = fopen('Domains.csv', 'r');

    if (!$inputFile) {
        $log->error("Error: Unable to open file 'Domains.csv'.");
    }

    $headers = fgetcsv($inputFile);

    if (!$headers) {
        fclose($inputClients);  // Close the file
        $log->error("Error: Unable to read headers from 'Domains.csv'.");
    }

    $log->info('Starting import of domains, hosts and contacts.');
    while (($row = fgetcsv($inputFile)) !== false) {
        $data = array_combine($headers, $row);
        
        $clid = getRegistrarIdByClid($pdo, $data['registrar_id']);
        
        // First, check if a record with the given identifier already exists
        $existingId = getContactIdByCID($pdo, $data['registrant_contact_id']);

        if ($existingId) {
            // If a record exists, use the existing ID
            $registrantId = $existingId;
        } else {
            // If no record exists, insert the new data
            $contactData = [
                $data['registrant_contact_id'], // identifier
                formatPhoneNumber($data['registrant_Phone']), // voice
                null, // voice_x
                formatPhoneNumber($data['registrant_fax']), // fax
                null, // fax_x
                $data['registrant_email'], // email
                null, // nin
                null, // nin_type
                $clid, // clid
                $clid, // crid
                formatTimestamp($data['create_date']) // crdate
            ];

            // Inserting into `contact` table
            $stmt = $pdo->prepare("INSERT INTO contact (identifier, voice, voice_x, fax, fax_x, email, nin, nin_type, clid, crid, crdate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute($contactData);

            // Getting the last inserted contact_id
            $registrantId = $pdo->lastInsertId();
        }

        // Preparing data for `contact_postalInfo` table
        $postalInfoData = [
            $registrantId, // contact_id
            'int', // type (assuming 'int' for international)
            $data['registrant_name'], // name
            $data['registrant_organisation'], // org
            $data['registrant_address_1'], // street1
            $data['registrant_address_2'], // street2
            $data['registrant_address_3'], // street3
            $data['registrant_city'], // city
            $data['registrant_state_province'], // sp
            $data['registrant_postalcode'], // pc
            formatCountryCode($data['registrant_countrycode']) // cc
        ];

        // Inserting into `contact_postalInfo` table
        $stmt = $pdo->prepare("INSERT IGNORE INTO contact_postalInfo (contact_id, type, name, org, street1, street2, street3, city, sp, pc, cc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute($postalInfoData);
        
        // Inserting into `contact_authInfo` table
        $authInfoData = [
            $registrantId, // contact_id
            'pw', // authtype
            generateRandomString() // authinfo
        ];

        $stmt = $pdo->prepare("INSERT IGNORE INTO contact_authInfo (contact_id, authtype, authinfo) VALUES (?, ?, ?)");
        $stmt->execute($authInfoData);

        // Inserting into `contact_status` table
        $statusData = [
            $registrantId, // contact_id
            'ok' // status
        ];

        $stmt = $pdo->prepare("INSERT IGNORE INTO contact_status (contact_id, status) VALUES (?, ?)");
        $stmt->execute($statusData);

        // Inserting into the 'domain' table
        $tldId = getTldId($pdo, $data['name']);
        $createdOn = formatTimestamp($data['create_date']);
        $expiryDate = formatTimestamp($data['expiry_date']);

        $sql = "INSERT INTO domain (name, tldid, registrant, crdate, exdate, clid, crid) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['name'], $tldId, $registrantId, $createdOn, $expiryDate, $clid, $clid]);

        $domainId = $pdo->lastInsertId();

        // Determine the appropriate status for the database based on $data['status']
        switch ($data['status']) {
            case 'domain_status_active':
                $status = 'ok';
                break;
            case 'domain_status_inactive':
                $status = 'inactive';
                break;
            case 'domain_status_excluded':
                $status = 'clientHold';
                break;
            case 'domain_status_pending_purge':
                $status = 'pendingDelete';
                break;
            default:
                $status = 'ok'; // Default to 'ok' if $data['status'] is empty or unrecognized
        }

        // Inserting domain status into 'domain_status' table
        $sql = "INSERT INTO domain_status (domain_id, status) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$domainId, $status]);
        
        // Inserting into `domain_authInfo` table
        $authInfoData = [
            $domainId, // domainId
            'pw', // authtype
            generateRandomString() // authinfo
        ];

        $stmt = $pdo->prepare("INSERT INTO domain_authInfo (domain_id, authtype, authinfo) VALUES (?, ?, ?)");
        $stmt->execute($authInfoData);
        
        // Inserting hosts into 'host' table
        for ($i = 1; $i <= 4; $i++) {
            if (isset($data["NameServer_$i"]) && !empty($data["NameServer_$i"])) {
                // Check if a record with the given name already exists
                $existingId = getHostIdByName($pdo, $data["NameServer_$i"]);

                if ($existingId) {
                    // If a record exists, use the existing ID
                    $hostId = $existingId;
                } else {
                    // If no record exists, insert the new data
                    $hostName = $data["NameServer_$i"];
                    $createdOn = formatTimestamp($data['create_date']);
                    
                    $sql = "INSERT INTO host (name, domain_id, clid, crid, crdate) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$hostName, null, $clid, $clid, $createdOn]);
                    
                    $hostId = $pdo->lastInsertId();
                }

                // Inserting 'ok' status into 'host_status' table
                $statusSql = "INSERT IGNORE INTO host_status (host_id, status) VALUES (?, ?)";
                $statusStmt = $pdo->prepare($statusSql);
                $statusStmt->execute([$hostId, 'ok']);
            }
        }
        
        // Insert into domain_contact_map
        $contactTypes = ['admin' => 'admin_contact_id', 'billing' => 'billing_contact_id', 'tech' => 'tech_contact_id'];
        foreach ($contactTypes as $type => $field) {
            $contactIds = explode(',', $data[$field]);
            foreach ($contactIds as $contactId) {
                $contactId = getContactIdByCID($pdo, trim($contactId));
                if ($contactId !== null) {
                    $contactSql = "INSERT INTO domain_contact_map (domain_id, contact_id, type) VALUES (?, ?, ?)";
                    $contactStmt = $pdo->prepare($contactSql);
                    $contactStmt->execute([$domainId, $contactId, $type]);
                }
            }
        }

        // Insert into domain_host_map
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($data["NameServer_$i"])) {
                $hostId = getHostIdByName($pdo, $data["NameServer_$i"]);
                if ($hostId !== null) {
                    $hostSql = "INSERT INTO domain_host_map (domain_id, host_id) VALUES (?, ?)";
                    $hostStmt = $pdo->prepare($hostSql);
                    $hostStmt->execute([$domainId, $hostId]);
                }
            }
        }
    }

    fclose($inputFile);

    // Updating the 'host' table with domain_id
    $hostsStmt = $pdo->query("SELECT id, name FROM host WHERE domain_id IS NULL");
    while ($host = $hostsStmt->fetch()) {
        $domainName = strstr($host['name'], '.', true);
        $domainId = getDomainIdByName($pdo, $domainName);
        
        if ($domainId !== null) {
            $updateStmt = $pdo->prepare("UPDATE host SET domain_id = ? WHERE id = ?");
            $updateStmt->execute([$domainId, $host['id']]);
        }
    }
    
    $log->info('Starting insert of host IP addresses.');
    // Fetching all hosts
    $hostsStmt = $pdo->query("SELECT id, name FROM host");
    while ($host = $hostsStmt->fetch()) {
        $hostId = $host['id'];
        $hostName = $host['name'];

        // Get IPv4 address
        $ipv4 = gethostbyname($hostName);
        if ($ipv4 !== $hostName) {  // Check if a valid IPv4 address is returned
            $sql = "INSERT INTO host_addr (host_id, addr, ip) VALUES (?, ?, 'v4')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$hostId, $ipv4]);
        }

        // Get IPv6 address
        $dnsRecords = dns_get_record($hostName, DNS_AAAA);
        foreach ($dnsRecords as $record) {
            if (isset($record['ipv6'])) {
                $ipv6 = $record['ipv6'];
                $sql = "INSERT INTO host_addr (host_id, addr, ip) VALUES (?, ?, 'v6')";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$hostId, $ipv6]);
            }
        }
    }
    
    //TODO: Insert billing entries.    
    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}