<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\EidEasy;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SetaPDF_Core_Document;
use SetaPDF_Core_Type_Dictionary;
use SetaPDF_Core_Type_Name;
use SetaPDF_Signer_Exception;
use SetaPDF_Signer_Signature_DictionaryInterface;
use SetaPDF_Signer_Signature_DocumentInterface;
use SetaPDF_Signer_TmpDocument;

class Module implements SetaPDF_Signer_Signature_DictionaryInterface, SetaPDF_Signer_Signature_DocumentInterface
{
    /**
     * @var string
     */
    protected $apiUrl;

    /**
     * @var string
     */
    protected $clientId;

    /**
     * @var string
     */
    protected $clientSecret;

    /**
     * @var ClientInterface
     */
    protected $httpClient;

    /**
     * @var RequestFactoryInterface
     */
    protected $requestFactory;

    /**
     * @var StreamFactoryInterface
     */
    protected $streamFactory = [];

    /**
     * @var array
     */
    protected $lastResponseData;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        string $clientId,
        string $clientSecret,
        bool $sandbox = false
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->apiUrl = $sandbox ? 'https://test.eideasy.com' : 'https://id.eideasy.com';
    }

    /**
     * Updates the signature dictionary.
     *
     * PAdES requires special Filter and SubFilter entries in the signature dictionary.
     *
     * @param SetaPDF_Core_Type_Dictionary $dictionary
     * @throws SetaPDF_Signer_Exception
     */
    public function updateSignatureDictionary(SetaPDF_Core_Type_Dictionary $dictionary)
    {
        /* do some checks:
         * - entry with the key M in the Signature Dictionary
         */
        if (!$dictionary->offsetExists('M')) {
            throw new SetaPDF_Signer_Exception(
                'The key M (the time of signing) shall be present in the signature dictionary to conform with PAdES.'
            );
        }

        $dictionary['SubFilter'] = new SetaPDF_Core_Type_Name('ETSI.CAdES.detached', true);
        $dictionary['Filter'] = new SetaPDF_Core_Type_Name('Adobe.PPKLite', true);
    }

    /**
     * Updates the document instance.
     *
     * @param SetaPDF_Core_Document $document
     * @see ETSI TS 102 778-3 V1.2.1 - 4.7 Extensions Dictionary
     * @see ETSI EN 319 142-1 V1.1.0 - 5.6 Extension dictionary
     */
    public function updateDocument(SetaPDF_Core_Document $document)
    {
        $extensions = $document->getCatalog()->getExtensions();
        $extensions->setExtension('ESIC', '1.7', 2);
    }

    /**
     * @param SetaPDF_Signer_TmpDocument $tmpDocument
     * @param string $filename
     * @param string $redirect Where to redirect the user after successful signing.
     *                         In case signing happens in iFrame then top window is redirected.
     * @return ProcessData
     * @throws ClientExceptionInterface
     * @throws Exception
     * @see https://documenter.getpostman.com/view/3869493/Szf6WoG1#74939bae-2c9b-459c-9f0b-8070d2bd32f7
     */
    public function prepareDocument(
        SetaPDF_Signer_TmpDocument $tmpDocument, string $filename, string $redirect, string $fieldName
    ): ProcessData
    {
        $hash = base64_encode(hash_file('sha256', $tmpDocument->getHashFile()->getPath(), true));

        $request = (
            $this->requestFactory->createRequest('POST', $this->apiUrl . '/api/signatures/prepare-files-for-signing')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode([
                // todo need some of this properties accessable from outside?
                "client_id" => $this->clientId,
                "secret" => $this->clientSecret,
                "signature_redirect" => $redirect,
                "nodownload" => true,
                "noemails" => true,
                "hide_preview_download" => true,
                "container_type" => "cades",
                "files" => [
                    [
                        "fileName" => $filename,
                        "mimeType" => 'application/pdf',
                        "fileContent" => $hash
                    ],
                ]
            ])))
        );

        $response = $this->httpClient->sendRequest($request);
        $responseBody = $response->getBody()->getContents();
        if ($response->getStatusCode() !== 200) {
            throw new Exception(\sprintf(
                'Unexpected response status code (%d). Response: %s',
                $response->getStatusCode(),
                $responseBody
            ));
        }

        $responseData = json_decode($responseBody, true);
        $this->lastResponseData = $responseData;
        if (($responseData['status'] ?? 'error') !== 'OK') {
            throw new Exception('Error while preparing files for signing. ' . json_encode($responseData));
        }
        return new ProcessData($responseData['doc_id'], $tmpDocument, $fieldName);
    }

    /**
     * @param string $docId
     * @return string The signature value
     * @throws ClientExceptionInterface
     * @throws Exception
     * @see https://documenter.getpostman.com/view/3869493/Szf6WoG1#962a183b-3e82-4053-9e35-230e9cabb313
     */
    public function fetchSignature(string $docId): string
    {
        $request = (
            $this->requestFactory->createRequest('POST', $this->apiUrl . '/api/signatures/download-signed-file')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode([
                "client_id" => $this->clientId,
                "secret" => $this->clientSecret,
                "doc_id" => $docId
            ])))
        );
        $response = $this->httpClient->sendRequest($request);
        $responseBody = $response->getBody()->getContents();
        if ($response->getStatusCode() !== 200) {
            throw new Exception(\sprintf(
                'Unexpected response status code (%d). Response: %s',
                $response->getStatusCode(),
                $responseBody
            ));
        }

        $responseData = json_decode($responseBody, true);
        $this->lastResponseData = $responseData;
        if (($responseData['status'] ?? 'error') !== 'OK') {
            throw new Exception('Error while downloading signed file. ' . json_encode($responseData));
        }

        return base64_decode($responseData['signed_file_contents']);
    }

    /**
     * Updates the document security store by the last received revoke information.
     *
     * @param \SetaPDF_Core_Document $document
     * @param string $fieldName The signature field, that was signed.
     * @throws \SetaPDF_Signer_Asn1_Exception
     */
    public function updateDss(\SetaPDF_Core_Document $document, string $fieldName)
    {
        $responseData = $this->getLastResponseData();
        if (!isset($responseData['pades_dss_data'])) {
            throw new \BadMethodCallException('No verification data available.');
        }

        $vri = $responseData['pades_dss_data'];

        $crls = [];
        $ocsps = [];
        $certificates = [];

        foreach ($vri['crls'] ?? [] as $crl) {
            $crls[] = base64_decode($crl);
        }

        foreach ($vri['ocsps'] ?? [] as $ocsp) {
            $ocsps[] = base64_decode($ocsp);
        }

        foreach ($vri['certificates'] ?? [] as $certificate) {
            $certificates[] = base64_decode($certificate);
        }

        $dss = new \SetaPDF_Signer_DocumentSecurityStore($document);
        $dss->addValidationRelatedInfoByFieldName($fieldName, $crls, $ocsps, $certificates);
    }

    public function getLastResponseData(): array
    {
        return $this->lastResponseData;
    }
}
