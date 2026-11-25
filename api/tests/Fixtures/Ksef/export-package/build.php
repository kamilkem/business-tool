<?php

declare(strict_types=1);

/*
 * Builds the encrypted test package of a KSeF export (adr/0039, tests/Fixtures/Ksef/README.md):
 * a ZIP with `_metadata.json` and two FA(3) invoices, split into two parts, each encrypted on its own
 * with AES-256-CBC (PKCS#7) under the key and IV that KsefExportKey derives from the test secret, NIP
 * and key id below, and the matching export status (`invoices-export-status-succeeded.json`).
 *
 * Run from api/: php tests/Fixtures/Ksef/export-package/build.php
 */

use App\Ksef\Infrastructure\Http\KsefExportKey;

require dirname(__DIR__, 4).'/vendor/autoload.php';

const SECRET = 'fixture-export-key-secret';
const NIP = '7251964308';
const KEY_ID = '019ab6c0-0000-7000-8000-000000000039';

$fixtures = dirname(__DIR__);
$invoices = [
    // Listed out of order on purpose: the client sorts by permanent-storage date.
    ['5218630497-20261104-0100E5F6A7B8-1C', 'Fa3/vat-several-rates.xml', '2026-11-04', '2026-11-04T13:02:14.9001234+00:00'],
    ['3947150208-20261103-0100A0B1C2D3-4F', 'invoice-fa3.xml', '2026-11-03', '2026-11-03T09:15:07.3881234+00:00'],
];

$zipPath = tempnam(sys_get_temp_dir(), 'ksef-fixture-');
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::OVERWRITE);
$metadata = [];
foreach ($invoices as [$ksefNumber, $file, $issueDate, $storedAt]) {
    $xml = (string) file_get_contents($fixtures.'/'.$file);
    $zip->addFromString($ksefNumber.'.xml', $xml);
    $zip->setMtimeName($ksefNumber.'.xml', 1794700800);
    $metadata[] = [
        'ksefNumber' => $ksefNumber,
        'invoiceNumber' => 'FV/'.$ksefNumber,
        'issueDate' => $issueDate,
        'invoicingDate' => $storedAt,
        'acquisitionDate' => $storedAt,
        'permanentStorageDate' => $storedAt,
        'seller' => ['nip' => substr($ksefNumber, 0, 10), 'name' => 'Dostawca Testowy'],
        'buyer' => ['identifier' => ['type' => 'Nip', 'value' => NIP], 'name' => 'Bar Testowy'],
        'netAmount' => 100.0,
        'grossAmount' => 123.0,
        'vatAmount' => 23.0,
        'currency' => 'PLN',
        'invoicingMode' => 'Online',
        'invoiceType' => 'Vat',
        'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
        'isSelfInvoicing' => false,
        'hasAttachment' => false,
        'invoiceHash' => base64_encode(hash('sha256', $xml, true)),
        'thirdSubjects' => [],
    ];
}
$zip->addFromString('_metadata.json', json_encode(['invoices' => $metadata], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$zip->setMtimeName('_metadata.json', 1794700800);
$zip->close();
$archive = (string) file_get_contents($zipPath);
unlink($zipPath);

$key = KsefExportKey::derive(SECRET, NIP, KEY_ID);
$parts = [];
$half = max(1, intdiv(strlen($archive), 2) + 1);
foreach (str_split($archive, $half) as $i => $plain) {
    $ordinal = $i + 1;
    $name = sprintf('20261125-EH-2D2C11B000-E9C9ED8340-EE-%03d.zip.aes', $ordinal);
    $encrypted = openssl_encrypt($plain, 'aes-256-cbc', $key->key, OPENSSL_RAW_DATA, $key->iv);
    file_put_contents(__DIR__.'/'.$name, $encrypted);
    $parts[] = [
        'ordinalNumber' => $ordinal,
        'partName' => $name,
        'method' => 'GET',
        'url' => 'https://ksef-storage.example/invoice-part/'.$name.'?sig=fixture-signature',
        'partSize' => strlen($plain),
        'partHash' => base64_encode(hash('sha256', $plain, true)),
        'encryptedPartSize' => strlen((string) $encrypted),
        'encryptedPartHash' => base64_encode(hash('sha256', (string) $encrypted, true)),
        'expirationDate' => '2026-11-25T23:00:00.0000000+00:00',
    ];
}

file_put_contents($fixtures.'/invoices-export-status-succeeded.json', json_encode([
    'status' => ['code' => 200, 'description' => 'Eksport faktur zakończony sukcesem'],
    'completedDate' => '2026-11-25T22:00:05.1234567+00:00',
    'packageExpirationDate' => '2026-12-02T22:00:05.1234567+00:00',
    'package' => [
        'invoiceCount' => count($invoices),
        'size' => strlen($archive),
        // Reversed on purpose: the client joins the parts by ordinalNumber.
        'parts' => array_reverse($parts),
        'isTruncated' => false,
        'permanentStorageHwmDate' => '2026-11-25T21:30:00.1234567+00:00',
        'compressionType' => 'Zip',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

echo "Built a package of ".count($invoices)." invoices in ".count($parts)." parts.\n";
