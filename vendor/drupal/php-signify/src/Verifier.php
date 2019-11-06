<?php

namespace Drupal\Signify;

class Verifier
{
    const COMMENTHDR = 'untrusted comment: ';
    const COMMENTHDRLEN = 19;
    const COMMENTMAXLEN = 1024;

    /**
     * @var string
     */
    protected $publicKeyRaw;

    /**
     * @var VerifierB64Data
     */
    protected $publicKey;

    /**
     * @var string $now
     */
    protected $now;

    /**
     * Verifier constructor.
     *
     * @param string $public_key
     *   A public key generated by the BSD signify application.
     */
     function __construct($public_key_raw) {
         $this->publicKeyRaw = $public_key_raw;
     }

    /**
     * Get the raw public key in use.
     *
     * @return string
     *   The public key.
     */
     public function getPublicKeyRaw(){
         return $this->publicKeyRaw;
     }

    /**
     * Get the public key data.
     *
     * @return \Drupal\Signify\VerifierB64Data
     *   An object with the validated and decoded public key data.
     *
     * @throws \Drupal\Signify\VerifierException
     */
     public function getPublicKey() {
         if (!$this->publicKey) {
             $this->publicKey = $this->parseB64String($this->publicKeyRaw, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
         }
         return $this->publicKey;
     }

    /**
     * Gets a \DateTime object modeling "now".
     *
     * @return \DateTime
     *   An object representing the current date in UTC for checking expiration.
     */
     public function getNow(\DateTime $now = NULL)
     {
         $date_format = 'Y-m-d';
         if (empty($now)) {
             $now = gmdate($date_format);
         }
         else {
             $now = $now->format($date_format);
         }
         $now_dt = \DateTime::createFromFormat($date_format, $now, new \DateTimeZone('UTC'));
         if (!$now_dt instanceof \DateTime) {
             throw new VerifierException('Unexpected date format of current date.');
         }
         return $now_dt;
     }

    /**
     * Parse the contents of a base 64 encoded file.
     *
     * @param string $b64
     *   The file contents.
     * @param int $length
     *   The length of the data, either 32 or 64 bytes.
     *
     * @return \Drupal\Signify\VerifierB64Data
     *   An object with the validated and decoded data.
     *
     * @throws \Drupal\Signify\VerifierException
     */
     public function parseB64String($b64, $length) {
         $parts = explode("\n", $b64);
         if (count($parts) !== 3) {
             throw new VerifierException("Invalid format; must contain two newlines, one after comment and one after base64");
         }
         $comment = $parts[0];
         if (substr($comment, 0, self::COMMENTHDRLEN) !== self::COMMENTHDR) {
             throw new VerifierException(sprintf("Invalid format; comment must start with '%s'", self::COMMENTHDR));
         }
         if (strlen($comment) > self::COMMENTHDRLEN + self::COMMENTMAXLEN) {
             throw new VerifierException(sprintf("Invalid format; comment longer than %d bytes", self::COMMENTMAXLEN));
         }
         return new VerifierB64Data($parts[1], $length);
     }

    /**
     * Verify a string message signed with plain Signify format.
     *
     * @param string $signed_message
     *   The string contents of the signify signature and message (e.g. the contents of a .sig file.)
     *
     * @return string
     *   The message if the verification passed.
     *
     * @throws \SodiumException
     *   Thrown when there is an unexpected crypto error or missing library.
     * @throws \Drupal\Signify\VerifierException
     *   Thrown when the message was not verified by the signature.
     */
    public function verifyMessage($signed_message) {
        $pubkey = $this->getPublicKey();

        // Simple split of signify signature and embedded message; input
        // validation occurs in parseB64String().
        $embedded_message_index = 0;
        for($i = 1; $i <= 2 && $embedded_message_index !== false; $i++) {
            $embedded_message_index = strpos($signed_message, "\n", $embedded_message_index + 1);
        }
        $signature = substr($signed_message, 0, $embedded_message_index + 1);
        $message = substr($signed_message, $embedded_message_index + 1);
        if ($message === false) {
            $message = '';
        }

        $sig = $this->parseB64String($signature, SODIUM_CRYPTO_SIGN_BYTES);
        if ($pubkey->keyNum !== $sig->keyNum) {
            throw new VerifierException('verification failed: checked against wrong key');
        }
        $valid = sodium_crypto_sign_verify_detached($sig->data, $message, $pubkey->data);
        if (!$valid) {
            throw new VerifierException('Signature did not match');
        }
        return $message;
    }

    /**
     * Verify a signed checksum list, and then verify the checksum for each file in the list.
     *
     * @param string $signed_checksum_list
     *   Contents of a signify signature file whose message is a file checksum list.
     * @param string $working_directory
     *   A directory on the filesystem that the file checksum list is relative to.
     *
     * @return int
     *   The number of files verified.
     *
     * @throws \SodiumException
     * @throws \Drupal\Signify\VerifierException
     *   Thrown when the checksum list could not be verified by the signature, or a listed file could not be verified.
     */
    public function verifyChecksumList($signed_checksum_list, $working_directory)
    {
        $checksum_list_raw = $this->verifyMessage($signed_checksum_list);
        return $this->verifyTrustedChecksumList($checksum_list_raw, $working_directory);
    }

    protected function verifyTrustedChecksumList($checksum_list_raw, $working_directory) {
        $checksum_list = new ChecksumList($checksum_list_raw, true);
        $failed_checksum_list =  new FailedCheckumFilter($checksum_list, $working_directory);

        foreach ($failed_checksum_list as $file_checksum)
        {
            // Don't just rely on a list of failed checksums, throw a more
            // specific exception.
            $actual_hash = @hash_file(strtolower($file_checksum->algorithm), $working_directory . DIRECTORY_SEPARATOR . $file_checksum->filename);
            // If file doesn't exist or isn't readable, hash_file returns false.
            if ($actual_hash === false) {
                throw new VerifierException("File \"$file_checksum->filename\" in the checksum list could not be read.");
            }
            // Any hash less than 64 is not secure.
            if (empty($actual_hash) || strlen($actual_hash) < 64) {
                throw new VerifierException("Failure computing hash for file \"$file_checksum->filename\" in the checksum list.");
            }
            // This method is used because hash_equals was added in PHP 5.6.
            // And we don't need timing safe comparisons.
            if ($actual_hash !== $file_checksum->hex_hash)
            {
                throw new VerifierException("File \"$file_checksum->filename\" does not pass checksum verification.");
            }
        }

        return $checksum_list->count();
    }

    /**
     * Verify the a signed checksum list file, and then verify the checksum for each file in the list.
     *
     * @param string $checksum_file
     *   The filename of a signed checksum list file.
     * @return int
     *   The number of files that were successfully verified.
     * @throws \SodiumException
     * @throws \Drupal\Signify\VerifierException
     *   Thrown when the checksum list could not be verified by the signature, or a listed file could not be verified.
     */
    public function verifyChecksumFile($checksum_file) {
        $absolute_path = realpath($checksum_file);
        if (empty($absolute_path))
        {
            throw new VerifierException("The real path of checksum list file at \"$checksum_file\" could not be determined.");
        }
        $working_directory = dirname($absolute_path);
        $signed_checksum_list = @file_get_contents($absolute_path);
        if (empty($signed_checksum_list))
        {
            throw new VerifierException("The checksum list file at \"$checksum_file\" could not be read.");
        }

        return $this->verifyChecksumList($signed_checksum_list, $working_directory);
    }

    /**
     * Verify a string message signed with CSIG chained-signature extended Signify format.
     *
     * @param string $chained_signed_message
     *   The string contents of the root/intermediate chained signify signature and message (e.g. the contents of a .csig file.)
     * @param \DateTime $now
     *   If provided, a \DateTime object modeling "now".
     *
     * @return string
     *   The message if the verification passed.
     * @throws \SodiumException
     * @throws \Drupal\Signify\VerifierException
     *   Thrown when the message was not verified.
     */
    public function verifyCsigMessage($chained_signed_message, \DateTime $now = NULL)
    {
        $csig_lines = explode("\n", $chained_signed_message, 6);
        $root_signed_intermediate_key_and_validity = implode("\n", array_slice($csig_lines, 0, 5)) . "\n";
        $this->verifyMessage($root_signed_intermediate_key_and_validity);

        $valid_through_dt = \DateTime::createFromFormat('Y-m-d', $csig_lines[2], new \DateTimeZone('UTC'));
        if (! $valid_through_dt instanceof \DateTime)
        {
            throw new VerifierException('Unexpected valid-through date format.');
        }
        $now_dt = $this->getNow($now);

        $diff = $now_dt->diff($valid_through_dt);
        if ($diff->invert) {
            throw new VerifierException(sprintf('The intermediate key expired %d day(s) ago.', $diff->days));
        }

        $intermediate_pubkey = implode("\n", array_slice($csig_lines, 3, 2)) . "\n";
        $chained_verifier = new self($intermediate_pubkey);
        $signed_message = implode("\n", array_slice($csig_lines, 5));
        return $chained_verifier->verifyMessage($signed_message);
    }

    /**
     * Verify a signed checksum list, and then verify the checksum for each file in the list.
     *
     * @param string $csig_signed_checksum_list
     *   Contents of a CSIG signature file whose message is a file checksum list.
     * @param string $working_directory
     *   A directory on the filesystem that the file checksum list is relative to.
     * @param \DateTime $now
     *   If provided, a \DateTime object modeling "now".
     *
     * @return int
     *   The number of files verified.
     *
     * @throws \SodiumException
     * @throws \Drupal\Signify\VerifierException
     *   Thrown when the checksum list could not be verified by the signature, or a listed file could not be verified.
     */
    public function verifyCsigChecksumList($csig_signed_checksum_list, $working_directory, \DateTime $now = NULL)
    {
        $checksum_list_raw = $this->verifyCsigMessage($csig_signed_checksum_list, $now);
        return $this->verifyTrustedChecksumList($checksum_list_raw, $working_directory);
    }

    /**
     * Verify the a signed checksum list file, and then verify the checksum for each file in the list.
     *
     * @param string $checksum_file
     *   The filename of a .csig signed checksum list file.
     * @param \DateTime $now
     *   If provided, a \DateTime object modeling "now".
     *
     * @return int
     *   The number of files that were successfully verified.
     *
     * @throws \SodiumException
     * @throws \Drupal\Signify\VerifierException
     *   Thrown when the checksum list could not be verified by the signature, or a listed file could not be verified.
     */
    public function verifyCsigChecksumFile($csig_checksum_file, \DateTime $now = NULL)
    {
        $absolute_path = realpath($csig_checksum_file);
        if (empty($absolute_path))
        {
            throw new VerifierException("The real path of checksum list file at \"$csig_checksum_file\" could not be determined.");
        }
        $working_directory = dirname($absolute_path);
        $signed_checksum_list = file_get_contents($absolute_path);
        if (empty($signed_checksum_list))
        {
            throw new VerifierException("The checksum list file at \"$csig_checksum_file\" could not be read.");
        }

        return $this->verifyCsigChecksumList($signed_checksum_list, $working_directory, $now);
    }
}