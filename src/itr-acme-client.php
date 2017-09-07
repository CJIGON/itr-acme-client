<?php
/**
 * @package itr-acme-client
 * @link http://itronic.at
 * @copyright Copyright (C) 2017 ITronic Harald Leithner.
 * @license GNU General Public License v3
 *
 * This file is part of itr-acme-client.
 *
 * isp is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * isp is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PhpStorm.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Class itrAcmeClient Main class
 */
class itrAcmeClient {

  /**
   * @var bool Set API endpoint to testing
   */
  public $debug = false;

  /**
   * @var string The ACME endpoint of the Certificate Authority
   *
   * This is the Let's Encrypt ACME API endpoint
   */
  public $ca = 'https://acme-v01.api.letsencrypt.org';

  /**
   * @var string The ACME testing endpoint of the Certificate Authority
   *
   * Keep in mind that letsencrypt as strict ratelimits, so use the testing
   * API endpoint if you test your implementation
   *
   * @see https://letsencrypt.org/docs/rate-limits/
   * @see https://letsencrypt.org/docs/staging-environment/
   */
  public $caTesting = 'https://acme-staging.api.letsencrypt.org';

  /**
   * @var string|itrAcmeChallengeManagerHttp The challenge Manager class or an itrAcmeChallengeManagerHttp object
   */
  public $challengeManager = 'itrAcmeChallengeManagerHttp';

  /**
   * @var string The CA Subscriber Agreement
   */
  public $agreement = 'https://letsencrypt.org/documents/LE-SA-v1.1.1-August-1-2016.pdf';

  /** Certificate Information */

  /**
   * @var array The Distinguished Name to be used in the certificate
   */
  public $certDistinguishedName = [
    /** @var string The certificate ISO 3166 country code */
    'countryName' => 'AT',
    /** Optional Parameters
     * 'stateOrProvinceName'    => 'Vienna',
     * 'localityName'           => 'Vienna',
     * 'organizationName'       => '',
     * 'organizationalUnitName' => '',
     * 'street'                 => ''
     */
  ];

  /**
   * @var string The root directory of the certificate store
   */
  public $certDir = '/etc/ssl';

  /**
   * @var string This token will be attached to the $certDir, if empty the first domainname is used
   */
  public $certToken = '';

  /**
   * @var string The root directory of the account store
   */
  public $certAccountDir = '/etc/ssl/accounts';

  /**
   * @var string This token will be attached to the $certAccountDir
   */
  public $certAccountToken = '';

  /**
   * @var array The certificate contact information
   */
  public $certAccountContact = [
    'mailto:cert-admin@example.com',
    'tel:+12025551212'
  ];

  /**
   * @var string The key types of the certificates we want to create (currently RSA only)
   */
  public $certKeyTypes = [
    'RSA'
  ];

  /**
   * @var string The key bit size of the certificate
   */
  public $certRsaKeyBits = 2048;

  /**
   * @var string The Digest Algorithm
   */
  public $certDigestAlg = 'sha256';

  /**
   * @var string The Diffie-Hellman File, if relative we use the $certAccountDir, if empty don't create it
   */
  public $dhParamFile = 'dh2048.pem';

  /**
   * @var string The root directory of the domain
   */
  public $webRootDir = '/var/www/html';

  /**
   * @var int The file permission the challenge needs so the webserver can read it
   */
  public $webServerFilePerm = 0644;

  /**
   * @var bool Append the domain to the $webRootDir to build the challenge path
   */
  public $appendDomain = false;

  /**
   * @var bool Append /.well-known/acme-challenge to the $webRootDir to build the challenge path
   */
  public $appendWellKnownPath = true;


  /**
   * @var \Psr\Log\LoggerInterface|null The logger to use, loglevel is always info
   */
  public $logger = null;

  /**
   * @var array Internal function that holds the last https request
   */
  private $lastResponse;

  /**
   * Initialise the object
   *
   * @return bool True if everything is ok
   * @throws Exception for Fatal errors
   */
  public function init() {

    // check if we are already initialised
    $this->log('Start initialisation.', 'debug');

    static $done = false;

    if ($done) {
      $this->log('Object already initialised.', 'critical');
      throw new \RuntimeException('Object already initialised!', 500);
    }

    $done = true;

    // build and clean up variables
    rtrim($this->certDir, '/');

    // if certAccountDir is relativ we prepend the certDir
    if (substr($this->certAccountDir, 0, 1) !== '/') {
      $this->certAccountDir = $this->certDir . '/' . $this->certAccountDir;
    }

    rtrim($this->certAccountDir, '/');

    // Add certAccountToken to AccountDir if set
    if (!empty($this->certAccountToken)) {
      $this->certAccountDir .= '/' . $this->certAccountToken;
    }

    // change API endpoint if debug is true
    if ($this->debug) {
      $this->ca = $this->caTesting;
    }

    // Check if default contact information has been changed
    if (is_array($this->certAccountContact) && (in_array('mailto:cert-admin@example.com', $this->certAccountContact) || in_array('tel:+12025551212', $this->certAccountContact))) {
      $this->log('Contact information has not been changed!', 'critical');
      throw new \RuntimeException('Contact information has not been changed!', 400);
    }

    // Create the challengeManager if its not already an object
    if (is_string($this->challengeManager)) {
      $this->challengeManager                = new $this->challengeManager;
      $this->challengeManager->itrAcmeClient = $this;
    }

    $this->log('Initialisation done.', 'debug');

    return true;
  }


  /**
   * Create a private and public key pair and register the account
   *
   * @return boolean True on success
   */
  public function createAccount() {

    $this->log('Starting account registration', 'info');

    if (is_file($this->certAccountDir . '/private.key')) {
      $this->log('Account already exists', 'info');
      return true;
    }

    // Generate the public + private key
    $this->generateKey($this->certAccountDir);


    // Build payload array
    $payload = [
      'resource' => 'new-reg'
    ];

    // Add Subscriber Agreement
    if (!empty($this->agreement)) {
      $payload['agreement'] = $this->agreement;
    }

    // Add contact information if exists
    if (!empty($this->contact)) {
      $payload['contact'] = (array)$this->contact;
    }

    $this->signedRequest('/acme/new-reg', $payload);

    if ($this->lastResponse['status'] !== 201) {
      $this->log('Account registration failed: ' . $this->lastResponse['status'], 'critical');
      throw new \RuntimeException('Account registration failed: ' . $this->lastResponse['status'], 500);
    }

    $this->log('Account registration completed', 'notice');

    return true;
  }

  /**
   * Create a public private keypair for all given domains and sign it
   *
   * @param array $domains A list of domainnames
   */
  public function signDomains(array $domains) {
    $this->log('Starting certificate generation for domains', 'info');

    // Load private account key
    $privateAccountKey = openssl_pkey_get_private('file://' . $this->certAccountDir . '/private.key');

    if ($privateAccountKey === false) {
      $this->log('Cannot read private account key: ' . openssl_error_string(), 500, 'critical');
      throw new \RuntimeException('Cannot read private account key: ' . openssl_error_string(), 500);
    }

    // Load private key details
    $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

    // check if all domains are reachable for us
    foreach ($domains as $domain) {

      $this->log('Check local access for domain: ' . $domain, 'debug');

      // Ask the challengeManager to validate Domain control
      try {
        if (!$this->challengeManager->validateDomainControl($domain)) {
          throw new \RuntimeException('Failed to validate control of ' . $domain, 500);
        }
      } catch (\RuntimeException $e) {
        $this->log($e->getMessage(), 'critical');
        throw $e;
      }
    }
    $this->log('Check local successfully completed!', 'info');

    // Get challenge and validate each domain
    foreach ($domains as $domain) {

      $this->log('Requesting challenges for domain ' . $domain, 'info');

      // Get available challenge methods for domain
      $this->signedRequest('/acme/new-authz', [
        'resource'   => 'new-authz',
        'identifier' => [
          'type'  => 'dns',
          'value' => $domain
        ]
      ]);

      if ($this->lastResponse['status'] !== 201) {
        $this->log('Error getting available challenges for Domain ' . $domain, 'critical');
        throw new \RuntimeException('Error getting available challenges for Domain ' . $domain, 500);
      }

      // Decode json body from request
      $response = json_decode($this->lastResponse['body'], true);

      // Check if our challengeManager is supported
      $challenge = false;
      foreach ($response['challenges'] as $k => $v) {
        if ($this->challengeManager->type == $v['type']) {
          $challenge = $v;
          break;
        }
      }
      if (!$challenge) {
        $this->log('Error cannot find compatible challange for Domain ' . $domain, 'critical');
        throw new \RuntimeException('Error cannot find compatible challange for Domain ' . $domain, 500);
      }

      $this->log('Found challenge for Domain ' . $domain, 'info');

      // We need last location for later validation
      preg_match('/Location: (.+)/i', $this->lastResponse['header'], $matches);
      $verificationUrl = trim($matches[1]);

      // Prepare Challenge
      $keyAuthorization = $this->challengeManager->prepareChallenge($domain, $challenge, $accountKeyDetails);

      // Notify the CA that the challenge is ready
      $this->log('Notify CA that the challenge is ready', 'info');

      $this->signedRequest($challenge['uri'], [
        'resource'         => 'challenge',
        'type'             => $this->challengeManager->type,
        'keyAuthorization' => $keyAuthorization,
        'token'            => $challenge['token']
      ]);

      // Check the status of the challenge, break after 90 seconds
      for ($i = 0; $i < 60; $i++) {
        $this->lastResponse         = RestHelper::get($verificationUrl);
        $this->lastResponse['json'] = json_decode($this->lastResponse['body'], true);

        if ($this->lastResponse['json']['status'] === 'pending') {
          $this->log('Verification is still pending...', 'info');
          usleep(1500);
        } else {
          break;
        }
      }

      // Check if we finished the challenge successfuly, if not cleanup and throw an exception
      if ($this->lastResponse['json']['status'] !== 'valid') {
        $this->challengeManager->cleanupChallenge($domain, $challenge);
        $this->log('Verification Status: ' . $this->lastResponse['json']['status'] . ' Repsonse: ' . $this->lastResponse['body'], 'critical');
        throw new \RuntimeException('Verification Status: ' . $this->lastResponse['json']['status'] . ' Repsonse: ' . $this->lastResponse['body'], 500);
      }

      $this->log('Verification status: ' . $this->lastResponse['json']['status'], 'info');

      // Cleanup
      $this->challengeManager->cleanupChallenge($domain, $challenge);
    }

    // Get certificate directory
    $certDir = $this->certDir;
    rtrim($certDir, '/');

    if (!empty($this->certToken)) {
      $certDir .= '/' . $this->certToken;
    } else {
      $certDir .= '/' . $domains[0];
    }

    // Create new public private keys
    $privateDomainKey = $this->generateKey();

    // Generate a cerfication signing request for all domains
    $csr = $this->generateCsr($privateDomainKey, $domains, $certDir);

    // Convert base64 to base64 url safe
    preg_match('/REQUEST-----(.*)-----END/s', $csr, $matches);
    $csr64 = trim(resthelper::base64url_encode(base64_decode($matches[1])));

    // request certificates creation
    $this->signedRequest('/acme/new-cert', [
      'resource' => 'new-cert',
      'csr'      => $csr64
    ]);

    if ($this->lastResponse['status'] !== 201) {
      throw new \RuntimeException('Invalid response code: ' . $this->lastResponse['status'] . ', ' . json_encode($result));
    }

    // We need last location for later validation
    preg_match('/Location: (.+)/i', $this->lastResponse['header'], $matches);
    $certificateUrl = trim($matches[1]);

    // Init variables
    $certChain   = '';
    $certificate = '';

    // Check the status of the challenge, break after 90 seconds
    for ($i = 0; $i < 60; $i++) {

      $this->lastResponse = RestHelper::get($certificateUrl);

      if ($this->lastResponse['status'] === 202) {

        $this->log('Certificate generation is still pending...', 'info');
        usleep(1500);

      } elseif ($this->lastResponse['status'] === 200) {

        $this->log('Certificate generation complete.', 'info');

        $cert64 = base64_encode($this->lastResponse['body']);
        $cert64 = chunk_split($cert64, 64, chr(10));

        $certificate = '-----BEGIN CERTIFICATE-----' . chr(10) . $cert64 . '-----END CERTIFICATE-----' . chr(10);

        // Load chain certificates
        preg_match_all('/Link: <(.+)>;rel="up"/', $this->lastResponse['header'], $matches);
        foreach ($matches[1] as $url) {
          $this->log('Load chain cert from: ' . $url, 'info');

          $result = RestHelper::get($url);

          if ($result['status'] === 200) {
            $cert64 = base64_encode($result['body']);
            $cert64 = chunk_split($cert64, 64, chr(10));

            $certChain .= '-----BEGIN CERTIFICATE-----' . chr(10) . $cert64 . '-----END CERTIFICATE-----' . chr(10);
          }
        }

        // Break for loop
        break;
      } else {
        $this->log('Certificate generation failed: Error code ' . $this->lastResponse['status'], 'critical');
        throw new \RuntimeException('Certificate generation failed: Error code ' . $this->lastResponse['status'], 500);
      }
    }

    if (empty($certificate)) {
      $this->log('Certificate generation failed: Reason unkown!', 'critical');
      throw new \RuntimeException('Certificate generation faild: Reason unkown!', 500);
    }

    foreach ($domains as $domain) {
      $this->log('Successfuly created certificate for domain: ' . $domain, 'notice');
    }

    $pem = [
      $this->certKeyTypes[0] => [
        'cert'  => $certificate,
        'chain' => $certChain,
        'key'   => $privateDomainKey
      ]
    ];

    if (!empty($this->dhParamFile)) {
      $pem['dh'] = $this->getDhParameters();
    }

    foreach ($pem as $keyType => $files) {

      if ($keyType === 'dh') continue;

      $pem[$keyType]['pem'] = implode('', $files);

      if (isset($pem['dh'])) {
        $pem[$keyType]['pem'] .= $pem['dh'];
      }
    }

    $this->log('Certificate generation finished.', 'info');

    return $pem;
  }

  /**
   * Generate a new public private key pair and save it to the given directory
   *
   * @param string|boolean $outputDir The directory for saveing the keys
   * @return string Private key
   */
  protected function generateKey($outputDir = false) {

    $this->log('Starting key generation.', 'info');

    $configargs = [
      'private_key_type' => $this->certKeyTypes[0],
      'private_key_bits' => $this->certRsaKeyBits,
    ];

    // create the certificate key
    $key = openssl_pkey_new($configargs);

    // Extract the new private key
    if (!openssl_pkey_export($key, $privateKey)) {
      $this->log('Private key export failed.', 'critical');
      throw new \RuntimeException('Private key export failed!', 500);
    }

    // Check if output directory exists, if not try to create it
    if ($outputDir !== false) {
      if (!is_dir($outputDir)) {
        $this->log('Output directory does not exist. Creating it.', 'info');
        @mkdir($outputDir, 0700, true);

        if (!is_dir($outputDir)) {
          $this->log('Failed to create output directory: ' . $outputDir, 'critical');
          throw new \RuntimeException('Failed to create output directory: ' . $outputDir, 500);
        }
      }

      if (file_put_contents($outputDir . '/private.key', $privateKey) === false) {
        $this->log('Failed to create private key file: ' . $outputDir . '/private.key', 'critical');
        throw new \RuntimeException('Failed to create private key file: ' . $outputDir, 500);
      }
    }

    $this->log('Key generation finished.', 'info');
    return $privateKey;
  }

  /**
   * Generate Diffie-Hellman Parameters
   *
   * @param int $bits The length in bits
   *
   * @return string The Diffie-Hellman Parameters as pem
   */
  public function getDhParameters($bits = 2048) {

    if (substr($this->dhParamFile, 0, 1) == '/') {
      $dhParamFile = $this->dhParamFile;
    } else {
      $dhParamFile = $this->certAccountDir . '/' . $this->dhParamFile;
    }

    // If file already exists, return its content
    if (file_exists($dhParamFile)) {
      $this->log('Diffie-Hellman Parameters already exists.', 'info');
      return file_get_contents($dhParamFile);
    }

    $ret            = 255;
    $descriptorspec = [
      // stdin is a pipe that the child will read from
      0 => [
        'pipe',
        'r'
      ],
      // stdout is a pipe that the child will write to
      1 => [
        'pipe',
        'w'
      ],
      // Write progress to stdout
      2 => STDOUT
    ];

    // Start openssl process to generate Diffie-Hellman Parameters
    $this->log('Start generate Diffie-Hellman Parameters', 'info');
    $process = proc_open('openssl dhparam -2 ' . (int)$bits, $descriptorspec, $pipes);

    // If process started successfully we get resource, we close input pipe and load the content of the output pipe
    if (is_resource($process)) {
      fclose($pipes[0]);

      $pem = stream_get_contents($pipes[1]);
      fclose($pipes[1]);

      // It is important that you close any pipes before calling
      // proc_close in order to avoid a deadlock
      $ret = proc_close($process);
    }

    // On error fail
    if ($ret > 0) {
      $this->log('Failed to generate Diffie-Hellman Parameters', 'critical');
      throw new \RuntimeException('Failed to generate Diffie-Hellman Parameters', 500);
    }

    $this->log('Diffie-Hellman Parameters generation finished.', 'info');

    // Write Parameters to file, ignore if location is not writeable
    @file_put_contents($dhParamFile, $pem);

    return $pem;
  }

  /**
   * Sends the payload signed with the account private key to the API endpoint
   *
   * @param string $uri Relativ uri to post the request to
   * @param array $payload The payload to send
   */
  public function signedRequest($uri, array $payload) {

    $this->log('Start signing request', 'info');

    // Load private account key
    $privateAccountKey = openssl_pkey_get_private('file://' . $this->certAccountDir . '/private.key');

    if ($privateAccountKey === false) {
      $this->log('Cannot read private account key: ' . openssl_error_string(), 500, 'critical');
      throw new \RuntimeException('Cannot read private account key: ' . openssl_error_string(), 500);
    }

    // Load private key details
    $privateKeyDetails = openssl_pkey_get_details($privateAccountKey);

    // Build header object for request
    $header = [
      'alg' => 'RS256',
      'jwk' => [
        'kty' => 'RSA',
        'n'   => RestHelper::base64url_encode($privateKeyDetails['rsa']['n']),
        'e'   => RestHelper::base64url_encode($privateKeyDetails['rsa']['e'])
      ]
    ];

    $protected = $header;

    // Get Replay-Nonce for next request
    if (empty($this->lastResponse) || strpos($this->lastResponse['header'], 'Replay-Nonce') === false) {
      $this->lastResponse = RestHelper::get($this->ca . '/directory');
    }

    if (preg_match('/Replay\-Nonce: (.+)/i', $this->lastResponse['header'], $matches)) {
      $protected['nonce'] = trim($matches[1]);
    } else {
      $this->log('Could not get Nonce!', 'critical');
      throw new \RuntimeException('Could not get Nonce!', 500);
    }

    // Encode base64 payload and protected header
    $payload64   = RestHelper::base64url_encode(str_replace('\\/', '/', json_encode($payload)));
    $protected64 = RestHelper::base64url_encode(json_encode($protected));

    // Sign payload and header with private key and base64 encode it
    openssl_sign($protected64 . '.' . $payload64, $signed, $privateAccountKey, 'SHA256');
    $signed64 = RestHelper::base64url_encode($signed);

    $data = [
      'header'    => $header,
      'protected' => $protected64,
      'payload'   => $payload64,
      'signature' => $signed64
    ];

    // Check if we got a relativ url and append ca url
    if (strpos($uri, '://') === false) {
      $uri = $this->ca . $uri;
    }

    $this->log('Sending signed request to ' . $uri, 'info');

    // Post Signed data to API endpoint
    $this->lastResponse = RestHelper::post($uri, json_encode($data));
  }

  /** Openssl functions */

  /**
   * Generate a certificate signing request
   *
   * @param $privateKey The Private key we want to sign
   * @param array $domains The Domains we want to sign
   * @return string the CSR
   */
  private function generateCsr($privateKey, array $domains) {

    $tempConfigHandle = tmpfile();
    $dn               = $this->certDistinguishedName;
    $dn['commonName'] = $domains[0];
    $keyConfig        = [
      'private_key_type' => $this->certKeyTypes[0],
      'digest_alg'       => $this->certDigestAlg,
      'private_key_bits' => $this->certRsaKeyBits,
      'config'           => stream_get_meta_data($tempConfigHandle)['uri']
    ];

    // We need openssl config file because else its not possible (2017) to create SAN certificates
    $tempConfigContent   = [];
    $tempConfigContent[] = '[req]';
    $tempConfigContent[] = 'distinguished_name = req_distinguished_name';
    $tempConfigContent[] = 'req_extensions = v3_req';
    $tempConfigContent[] = '';
    $tempConfigContent[] = '[req_distinguished_name]';
    $tempConfigContent[] = '';
    $tempConfigContent[] = '[v3_req]';
    $tempConfigContent[] = 'basicConstraints = CA:FALSE';
    $tempConfigContent[] = 'keyUsage = nonRepudiation, digitalSignature, keyEncipherment';
    $tempConfigContent[] = 'subjectAltName = @alt_names';
    $tempConfigContent[] = '';
    $tempConfigContent[] = '[alt_names]';

    $xcount = 0;
    foreach ($domains as $domain) {
      $xcount++;
      $tempConfigContent[] = 'DNS.' . $xcount . ' = ' . $domain;
    }

    fwrite($tempConfigHandle, implode(chr(10), $tempConfigContent));

    // Create new signing request
    $csr = openssl_csr_new($dn, $privateKey, $keyConfig);

    // Cleanup
    fclose($tempConfigHandle);

    if (!$csr) {
      $this->log('Signing request generation failed! (' . openssl_error_string() . ')', 'crtical');
      throw new \RuntimeException('Signing request generation failed! (' . openssl_error_string() . ')');
    }

    // Extract Signing request
    openssl_csr_export($csr, $csr_export);

    return $csr_export;
  }

  /** Utility functions */

  /**
   * Create the absolute path to the acme-challenge path
   *
   * @param string $domain The Domainname we need the path for
   * @return string The absolute path to the acme-challenge directory
   */
  public function getDomainWellKnownPath($domain) {
    $path = $this->webRootDir;

    rtrim($path, '/');

    if ($this->appendDomain) {
      $path .= '/' . $domain;
    }

    if ($this->appendWellKnownPath) {
      $path .= '/.well-known/acme-challenge';
    }

    return $path;
  }

  /**
   * Logging function, use \Psr\Log\LoggerInterface if set
   *
   * @param string $message The log message
   * @param string $level The log level used for Pse logging
   */
  public function log($message, $level = 'info') {
    if ($this->logger) {
      $this->logger->log($level, $message);
    } else {
      echo $message . chr(10);
    }
  }
}

interface itrAcmeChallengeManager {


  /**
   * This function validates if we control the domain so we can complete the challenge
   *
   * @param string $domain
   * @return mixed
   */
  public function validateDomainControl($domain);

  /**
   * Save the challenge token to the well-known path
   *
   * @param string $domain
   * @param array $challenge
   * @param array $accountKeyDetails
   */
  public function prepareChallenge($domain, $challenge, $accountKeyDetails);

}

abstract class itrAcmeChallengeManagerClass implements itrAcmeChallengeManager {
  /**
   * @var itrAcmeClient The itrAcmeClient Object
   */
  public $itrAcmeClient = null;

  /**
   * @var string The challenge type
   */
  public $type = '';
}

class itrAcmeChallengeManagerHttp extends itrAcmeChallengeManagerClass {

  /**
   * @var string The challenge type http
   */
  public $type = 'http-01';

  public function validateDomainControl($domain) {

    // Get Well-known Path and create it if it doesn't exists
    $domainWellKnownPath = $this->itrAcmeClient->getDomainWellKnownPath($domain);

    if (!is_dir($domainWellKnownPath)) {
      @mkdir($domainWellKnownPath, 0755, true);

      if (!is_dir($domainWellKnownPath)) {
        throw new \RuntimeException('Cannot create path: ' . $domainWellKnownPath, 500);
      }
    }

    // Save local_check.txt to the Well-Known Path
    $this->itrAcmeClient->log('Try saving local to: ' . $domainWellKnownPath . '/local_check.txt', 'debug');

    if (!file_put_contents($domainWellKnownPath . '/local_check.txt', 'OK')) {
      throw new \RuntimeException('Cannot create local check file at ' . $domainWellKnownPath, 500);
    }

    // Set webserver compatible permissions
    chmod($domainWellKnownPath . '/local_check.txt', $this->itrAcmeClient->webServerFilePerm);

    // Validate local_check.txt over http
    $result = RestHelper::get('http://' . $domain . '/.well-known/acme-challenge/local_check.txt');

    // Cleanup before validation because we want a clean directory if we fail validating
    unlink($domainWellKnownPath . '/local_check.txt');

    // Check for http error or wrong body contant
    if ($result['status'] !== 200 || $result['body'] !== 'OK') {
      throw new \RuntimeException('Failed to validate content of local check file at ' . $domainWellKnownPath, 500);
    }

    return true;
  }

  /**
   * Save the challenge token to the well-known path
   *
   * @param string $domain
   * @param array $challenge
   * @param array $accountKeyDetails
   */
  public function prepareChallenge($domain, $challenge, $accountKeyDetails) {

    // get the well-known path, we know that it already exists and we can write to it
    $domainWellKnownPath = $this->itrAcmeClient->getDomainWellKnownPath($domain);

    // Create a fingerprint in the correct order
    $fingerprint = [
      'e'   => RestHelper::base64url_encode($accountKeyDetails['rsa']['e']),
      'kty' => 'RSA',
      'n'   => RestHelper::base64url_encode($accountKeyDetails['rsa']['n'])
    ];

    // We need a sha256 hash
    $hash = hash('sha256', json_encode($fingerprint), true);

    // compile challenge token and base64 encoded hash togather
    $challengeBody = $challenge['token'] . '.' . RestHelper::base64url_encode($hash);

    // Save the token with the fingerpint in the well-known path and set file rights
    file_put_contents($domainWellKnownPath . '/' . $challenge['token'], $challengeBody);

    // Set webserver compatible permissions
    chmod($domainWellKnownPath . '/' . $challenge['token'], $this->itrAcmeClient->webServerFilePerm);

    // Validate that challenge repsonse is reachable
    $challengeResponseUrl = 'http://' . $domain . '/.well-known/acme-challenge/' . $challenge['token'];
    $result               = RestHelper::get($challengeResponseUrl);

    if ($result['body'] != $challengeBody) {
      throw new \RuntimeException('Cannot verify challange reposonse at: ' . $challengeResponseUrl, 500);
    }

    $this->itrAcmeClient->log('Token is available at ' . $challengeResponseUrl, 'info');

    return $challengeBody;

  }

  public function cleanupChallenge($domain, $challenge) {
    // get the well-known path, we know that it already exists and we can write to it
    $domainWellKnownPath = $this->itrAcmeClient->getDomainWellKnownPath($domain);

    unlink($domainWellKnownPath . '/' . $challenge['token']);

  }

}


/**
 * Class RestHelper
 */
class RestHelper {

  /** @var string Username */
  static $username;

  /** @var string Password */
  static $password;

  /**
   * Call the url as GET
   *
   * @param string $url The url
   * @param array $obj The parameters
   * @param string $return The Format of the result
   *
   * @return array|string  The result
   */
  public static function get($url, $obj = [], $return = 'print') {

    $curl = self::loadCurl($url);

    if (strrpos($url, '?') === false) {
      $url .= '?' . http_build_query($obj);
    }

    return self::execCurl($curl, $return);

  }

  /**
   * Call the url as POST
   *
   * @param string $url The url
   * @param array $obj The parameters
   * @param string $return The Format of the result
   *
   * @return array|string  The result
   */
  public static function post($url, $obj = [], $return = 'print') {

    $curl = self::loadCurl($url);

    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $obj);

    return self::execCurl($curl, $return);

  }

  /**
   * Call the url as PUT
   *
   * @param string $url The url
   * @param array $obj The parameters
   * @param string $return The Format of the result
   *
   * @return array|string  The result
   */
  public static function put($url, $obj = [], $return = 'print') {

    $curl = self::loadCurl($url);

    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_POSTFIELDS, $obj);

    return self::execCurl($curl, $return);

  }

  /**
   * Call the url as PUT
   *
   * @param string $url The url
   * @param array $obj The parameters
   * @param string $return The Format of the result
   *
   * @return array|string  The result
   */
  public static function delete($url, $obj = [], $return = 'print') {

    $curl = self::loadCurl($url);

    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($curl, CURLOPT_POSTFIELDS, $obj);

    return self::execCurl($curl, $return);

  }

  /**
   * Create a cUrl object
   *
   * @return resource   The curl resource
   */
  public static function loadCurl($url) {

    $curl = curl_init();

    curl_setopt_array($curl, [
      CURLOPT_URL            => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Content-Type: application/json'
      ],
      CURLOPT_HEADER         => true,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
      CURLOPT_USERPWD        => self::$username . ':' . self::$password
    ]);

    return $curl;
  }

  /**
   * Executes the cUrl request and outputs the formation
   *
   * @param  $curl    resource The cUrl object to fetch
   * @param  $return  string The result formation
   *
   * @return array|string   The result based on $return parameter
   */
  public static function execCurl($curl, $return = 'print') {

    $data = curl_exec($curl);
    $info = curl_getinfo($curl);
    curl_close($curl);

    $header = substr($data, 0, $info['header_size']);
    $body   = substr($data, $info['header_size']);

    if ($return == 'print') {
      return [
        'status' => $info['http_code'],
        'header' => $header,
        'body'   => $body
      ];
    } else {
      return $body;
    }
  }

  /**
   * Encode $data to base64 url safe
   *
   * @param string $data The input string
   * @return string The base64 url safe string
   */
  public static function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  /**
   * Decodes $data as base64 url safe string
   *
   * @param string $data The base64 url safe string
   * @return string The decoded string
   */
  public static function base64url_decode($data) {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
  }
}
