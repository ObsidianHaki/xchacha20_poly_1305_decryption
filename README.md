# GLPI RootDN Password Decryption

## 1. Why?

This script provides a way to decrypt the `rootdn_passwd` value stored in the GLPI database.

GLPI encrypts sensitive configuration values before storing them in the database. This includes LDAP credentials such as the LDAP RootDN password.

The encrypted database value cannot be read directly because it is protected using the XChaCha20-Poly1305 authenticated encryption algorithm.

To recover the original password, three components are required:

- **Ciphertext:** The encrypted password stored in the GLPI database.
- **Nonce:** The original 24-byte nonce generated during encryption.
- **Secret key:** The corresponding GLPI encryption key stored in `glpicrypt.key`.

The nonce is included in the encrypted database value, so it does not need to be obtained separately.

**Purpose:** This script extracts the nonce and ciphertext from the database value, loads the GLPI secret key, and decrypts the original password.

This is intended for authorized GLPI administration, troubleshooting and academic study.

---

## 2. What is this algorithm doing?

GLPI uses **XChaCha20-Poly1305 (AEAD)** through the PHP Sodium extension.

AEAD stands for Authenticated Encryption with Associated Data. It provides two important properties:

1. Confidentiality: The original password is encrypted.
2. Integrity and authenticity: Modifications to the encrypted data are detected during decryption.

### 2.1 Encryption process

GLPI performs the following operations:

**Step 1: Load the secret key**

GLPI reads the encryption key from:

`/opt/glpi/config/glpicrypt.key`

The key must contain exactly 32 bytes (256 bits).

**Step 2: Generate a random nonce**

```php
$nonce = random_bytes(
    SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
);
```

The nonce is 24 bytes (192 bits).

A new random nonce is generated for every encryption operation.

**Step 3: Encrypt the password**

```php
$encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
    $string,
    $nonce,
    $nonce,
    $key
);
```

The function arguments are:

| Argument        | Description                         |
| --------------- | ----------------------------------- |
| `$string`       | Original plaintext password         |
| First `$nonce`  | Additional Authenticated Data (AAD) |
| Second `$nonce` | Actual encryption nonce             |
| `$key`          | 32-byte secret key                  |

GLPI uses the same nonce twice: once as AAD and once as the actual nonce.

**Step 4: Store the encrypted value**

```php
return base64_encode($nonce . $encrypted);
```

GLPI concatenates the nonce and encrypted payload and encodes the result using Base64.

### 2.2 Stored database structure

The resulting database value has the following structure:

```text
BASE64(
    NONCE (24 bytes)
    +
    CIPHERTEXT (variable length)
    +
    AUTHENTICATION TAG (16 bytes)
)
```

The authentication tag is appended to the ciphertext by the Sodium encryption function.

### 2.3 Decryption process

The script reverses the encryption process:

1. Decode the Base64 database value.
2. Extract the first 24 bytes as the original nonce.
3. Extract the remaining bytes as ciphertext and authentication tag.
4. Read the 32-byte secret key from `glpicrypt.key`.
5. Pass the ciphertext, nonce, AAD and key to the Sodium decryption function.
6. Verify the authentication tag.
7. Return the original plaintext if authentication succeeds.

The decryption function is:

```php
$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
    $ciphertext,
    $nonce,
    $nonce,
    $key
);
```

If the key, nonce, AAD or encrypted data is incorrect, authentication fails and the function returns `false`.

### 2.4 Important cryptographic properties

| Component          |     Size | Purpose                                 |
| ------------------ | -------: | --------------------------------------- |
| Secret key         | 32 bytes | Encryption and decryption               |
| Nonce              | 24 bytes | Unique input for each encryption        |
| Authentication tag | 16 bytes | Integrity and authenticity verification |
| Ciphertext         | Variable | Encrypted password                      |

**Important:** The nonce is not secret. It is stored together with the ciphertext. The secret key in `glpicrypt.key` must be protected.

---

## 3. Prerequisites

Before running the script, ensure that the following requirements are met.

### 3.1 PHP

PHP CLI must be installed.

Check the PHP version:

```bash
php -v
```

### 3.2 Sodium extension

The PHP Sodium extension must be installed and enabled.

Check whether it is available:

```bash
php -m | grep sodium
```

Check the required algorithm constant:

```bash
php -r 'echo SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;'
```

Expected output:

```text
24
```

### 3.3 GLPI encryption key

You need access to the correct GLPI encryption key file:

```text
/opt/glpi/config/glpicrypt.key
```

The key must contain exactly 32 bytes.

Check its size:

```bash
wc -c /opt/glpi/config/glpicrypt.key
```

Expected output:

```text
32
```

Use the key belonging to the GLPI installation that encrypted the database value.

### 3.4 Database access

You need the encrypted `rootdn_passwd` value from the GLPI database.

The relevant database field is:

```sql
SELECT rootdn_passwd
FROM glpi_authldaps;
```

The value must be copied exactly, without additional formatting or modifications.

### 3.5 Permissions

The user executing the script must have read access to `glpicrypt.key`.

The key file should not be exposed through the web server or accessible to unauthorized users.

---

## 4. Usage

### 4.1 Save the script

Save the PHP script as:

```text
decrypt.php
```

### 4.2 Execute the script

Run it from the terminal:

```bash
php decrypt.php
```

### 4.3 Provide the encrypted database value

The script prompts for the encrypted `rootdn_passwd`:

```text
Enter rootdn_passwd:
```

Paste the Base64-encoded value obtained from the GLPI database.

### 4.4 Provide the GLPI key path

Next, enter the absolute path to the encryption key:

```text
Enter absolute path to glpicrypt.key:
```

For example:

```text
/opt/glpi/config/glpicrypt.key
```

### 4.5 Successful decryption

If the supplied database value and encryption key are correct, the output should indicate:

```text
Decryption successful!
Plaintext: [original password]
```

The script also displays diagnostic information, including the decoded data length, nonce length, ciphertext length and key length.

### 4.6 Troubleshooting

| Error                   | Possible cause                                                         |
| ----------------------- | ---------------------------------------------------------------------- |
| Invalid Base64 format   | Database value is not valid Base64                                     |
| Key file not found      | Incorrect path to `glpicrypt.key`                                      |
| Unable to read key file | Insufficient filesystem permissions                                    |
| Invalid GLPI key length | Key file does not contain exactly 32 bytes                             |
| Invalid nonce length    | Database value is incomplete or has an incorrect format                |
| Ciphertext is too short | Missing ciphertext or authentication tag                               |
| Decryption failed       | Wrong key, incorrect data, incorrect nonce/AAD or corrupted ciphertext |

### 4.7 Security considerations
    
- Never share or expose `glpicrypt.key`.
- Do not store the decrypted password in logs.
- Do not modify the original encrypted database value.
- Use only the matching key from the GLPI installation.
- Restrict access to the script and its output.
- Remove temporary scripts containing sensitive information when they are no longer needed.

---

## Academic Purpose and Ethical Disclaimer

This script is developed for **academic, educational and research purposes only**. It is not designed or intended to cause harm, compromise systems, or facilitate unauthorized access.

Its purpose is to demonstrate and understand how GLPI implements encryption and decryption using XChaCha20-Poly1305 and PHP Sodium.

**Ethical conduct and responsible use are required.**

The script should only be used on systems, databases and encryption keys for which the user has explicit authorization.

Academic or educational intent does not exempt anyone from applicable laws or responsibility for misuse. The user is responsible for ensuring that their activities comply with applicable legal requirements and ethical standards.