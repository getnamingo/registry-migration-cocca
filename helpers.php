<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    // Create a log channel
    $log = new Logger($channelName);

    // Set up the console handler
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u", // Date format
        true, // Allow inline line breaks
        true  // Ignore empty context and extra
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // Set up the file handler
    $fileHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u" // Date format
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    return $log;
}

function formatPhoneNumber($phoneNumber) {
    // Check if the phone number is empty or null
    if (empty($phoneNumber)) {
        return $phoneNumber;
    }

    // Remove hyphens and whitespaces
    $phoneNumber = str_replace(['-', ' '], '', $phoneNumber);

    // Ensure the phone number starts with a '+'
    if (substr($phoneNumber, 0, 1) !== '+') {
        $phoneNumber = '+' . $phoneNumber;
    }

    // Trim the phone number to the first 25 characters after the '+'
    $phoneNumber = substr($phoneNumber, 0, 26);

    return $phoneNumber;
}

function formatCountryCode($countryCode) {
    return strtoupper($countryCode);
}

function generateRandomString($length = 16) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ@';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function formatTimestamp($timestamp) {
    try {
        $dt = new DateTime($timestamp);
    } catch (Exception $e) {
        $dt = DateTime::createFromFormat('Y-m-d', $timestamp);
        $dt->setTime(0, 0, 0);
    }
    return $dt->format('Y-m-d H:i:s'); // MySQL datetime format
}

function getTldId($pdo, $domainName) {
    // Extract the TLD from the domain name
    $tld = getDomainTLD($domainName);

    // Prepare and execute the query to find the TLD ID
    $stmt = $pdo->prepare("SELECT id FROM domain_tld WHERE tld = ?");
    $stmt->execute([$tld]);
    $row = $stmt->fetch();

    // Return the TLD ID or null if not found
    return $row ? $row['id'] : null;
}

function getDomainTLD($domainName) {
    // Split the domain name into parts
    $parts = explode('.', $domainName);

    // Count the number of parts
    $numParts = count($parts);

    // If there are more than two parts (e.g., 'com.mu' in 'test.com.mu'),
    // concatenate the last two parts as the TLD
    if ($numParts > 2) {
        return '.' . $parts[$numParts - 2] . '.' . $parts[$numParts - 1];
    }

    // If there are only two or fewer parts, use the last part as the TLD
    return '.' . end($parts);
}

function getDomainIdByName($pdo, $domainName) {
    $stmt = $pdo->prepare("SELECT id FROM domain WHERE name = ?");
    $stmt->execute([$domainName]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

function getContactIdByCID($pdo, $contactId) {
    $stmt = $pdo->prepare("SELECT id FROM contact WHERE identifier = ?");
    $stmt->execute([$contactId]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

function getHostIdByName($pdo, $hostName) {
    $stmt = $pdo->prepare("SELECT id FROM host WHERE name = ?");
    $stmt->execute([$hostName]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

function getRegistrarIdByClid($pdo, $clid) {
    $stmt = $pdo->prepare("SELECT id FROM registrar WHERE clid = ?");
    $stmt->execute([$clid]);
    $row = $stmt->fetch();
    return $row ? $row['id'] : null;
}

function isEligibleForRenewal($status) {
    $pendingStatuses = ['domain_status_pending_purge', 'domain_status_pending_delete'];
    return in_array($status, $pendingStatuses);
}

function parseBool($value) {
    $value = strtolower($value);
    if ($value === 'true') return true;
    if ($value === 'false') return false;
    return null;
}