
<?php

function decode_rootdn_passwd(string $encoded_password): string {
    $decoded = base64_decode($encoded_password, true);

    if ($decoded === false) {
        throw new Exception("Invalid Base64 format.");
    }

    return $decoded;
}

function extract_nonce(string $decoded_password): string {
    return substr($decoded_password, 0, 24);
}

function extract_ciphertext(string $decoded_password): string {
    return substr($decoded_password, 24);
}

function read_glpi_key(string $key_path): string {

    if (!file_exists($key_path)) {
        throw new Exception("Key file not found: $key_path");
    }

    $key = file_get_contents($key_path);

    if ($key === false) {
        throw new Exception("Unable to read key file.");
    }

    echo "Key length: " . strlen($key) . " bytes\n";
    echo "Key valid: " .
        (strlen($key) === 32 ? "true" : "false") . "\n";

    if (strlen($key) !== 32) {
        throw new Exception("Invalid GLPI key length.");
    }

    return $key;
}

// MAIN PROGRAM

try {

    $encoded = readline("Enter rootdn_passwd: ");
    $key_path = readline("Enter absolute path to glpicrypt.key: ");

    $decoded = decode_rootdn_passwd($encoded);

    $nonce = extract_nonce($decoded);
    $ciphertext = extract_ciphertext($decoded);

    echo "\nDecoded length: " . strlen($decoded) . "\n";
    echo "Nonce length: " . strlen($nonce) . "\n";
    echo "Ciphertext length: " . strlen($ciphertext) . "\n";

    if (strlen($nonce) !== 24) {
        throw new Exception("Invalid nonce length.");
    }

    if (strlen($ciphertext) < 16) {
        throw new Exception("Ciphertext is too short.");
    }

    echo "Nonce (hex): " . bin2hex($nonce) . "\n";

    // Read GLPI secret key
    $key = read_glpi_key($key_path);

    // GLPI uses nonce as AAD
    $aad = $nonce;

    // Decrypt
    $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        $ciphertext,
        $aad,
        $nonce,
        $key
    );

    if ($plaintext === false) {
        throw new Exception(
            "Decryption failed: incorrect key, nonce, AAD or corrupted data."
        );
    }

    echo "\nDecryption successful!\n";
    echo "Plaintext: " . $plaintext . "\n";

} catch (Throwable $e) {

    echo "\nError: " . $e->getMessage() . "\n";

}
