<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\CurlHandler;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Mjelamanov\GuzzlePsr18\Client as Psr18Wrapper;
use setasign\SetaPDF\Signer\Module\EidEasy\Module;

date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

if (!file_exists(__DIR__ . '/settings.php')) {
    throw new RuntimeException('Missing settings.php!');
}
$settings = require __DIR__ . '/settings.php';

$file = __DIR__ . '/files/Laboratory-Report.pdf';
$demoUrl = $settings['demoUrl'] . '/demo.php';

$httpClient = new GuzzleClient([
    'handler' => new CurlHandler(),
    // note: guzzle requires this parameter to fully support PSR-18
    'http_errors' => false,
]);
// only required if you are using guzzle < 7
$httpClient = new Psr18Wrapper($httpClient);
$requestFactory = new RequestFactory();
$streamFactory = new StreamFactory();

session_start();

if (isset($_GET['reset'])) {
    unset($_SESSION[__FILE__]);
    $action = 'start';
} else {
    $action = $_GET['action'] ?? 'start';
}

$module = new Module(
    $httpClient,
    $requestFactory,
    $streamFactory,
    $settings['clientId'],
    $settings['clientSecret'],
    $settings['sandbox'] ?? false
);

switch ($action) {
    case 'start':
        $reader = new SetaPDF_Core_Reader_File($file);
        // let's get the document
        $document = SetaPDF_Core_Document::load($reader);

        // now let's create a signer instance
        $signer = new SetaPDF_Signer($document);
        $signer->setAllowSignatureContentLengthChange(false);
        $signer->setSignatureContentLength(36000);

        // set some signature properties
        $signer->setLocation($_SERVER['SERVER_NAME']);
        $signer->setContactInfo('+01 2345 67890123');
        $signer->setReason('Testing eid easy');

        $field = $signer->getSignatureField();
        $signer->setSignatureFieldName($field->getQualifiedName());

        $tmpDocument = $signer->preSign(new SetaPDF_Core_Writer_File(SetaPDF_Core_Writer_TempFile::createTempPath()), $module);
        $processData = $module->prepareDocument($tmpDocument, basename($file), $demoUrl . '?action=sign');

        $_SESSION[__FILE__] = [
            'processData' => $processData
        ];

        // use the web widget here: https://eideasy-widget.docs.eideasy.com/guide/#minimal
        echo <<<HTML
<!DOCTYPE html>
<head>
    <title>Sign pdf</title>
    <meta charset="utf-8">
</head>
<body>
    <script src="https://cdn.jsdelivr.net/npm/@eid-easy/eideasy-widget@2.11.1/dist/full/eideasy-widget.umd.min.js"
            integrity="sha256-cDmdyVFN9jvj0dG45ZgSbZ/d8WAhaA4TkmtJRj+ExAQ="
            crossorigin="anonymous"
    ></script>
    <div style="width: 1000px; margin: auto;">
        <h1>Sign pdf</h1>
        <iframe src="{$demoUrl}?action=preview" style="width:100%; height: 500px;"></iframe>
        <div id="widgetHolder" class="widgetHolder"></div>
    </div>
    
    <script type="text/javascript">
const widgetHolder = document.getElementById('widgetHolder');
const eidEasyWidget = document.createElement('eideasy-widget');

const settings = {
  clientId: '{$settings['clientId']}',
  docId: '{$processData->getDocId()}',
  countryCode: 'EE', // ISO 3166  two letter country code
  language: 'en', // ISO 639-1 two letter language code,
  sandbox: true,
  redirectUri: '{$demoUrl}?action=sign',
  enabledMethods: {
    signature: 'all'
  },
  selectedMethod: null,
  enabledCountries: 'all',
  // use these to prefill the input values
  inputValues: {
    idcode: '60001019906',
    phone: '00000766',
  },
  onSuccess: function (data) {
    console.log(data);
    if (data.data.status === 'OK') {
      console.log('Successfully signed document');
      // reload the page on success
      window.location.replace(data.data.signature_redirect);
    } else {
      alert('Error while signing document');
    }
  },
  onFail: function (error) {
    console.log(error);
  },
}

Object.keys(settings).forEach(key => {
  eidEasyWidget[key] = settings[key];
});

widgetHolder.appendChild(eidEasyWidget);
</script>
</body>
HTML;

        break;

    case 'preview':
        $doc = file_get_contents($file);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($file));
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . strlen($doc));
        echo $doc;
        flush();
        break;

    case 'sign':
        if (!isset($_SESSION[__FILE__]['processData'])) {
            echo 'No process data found.<hr/>If you want to restart the signature process click here: <a href="?reset=1">Restart</a>';
            break;
        }
        $processData = $_SESSION[__FILE__]['processData'];
        try {
            // check whether the document is already signed
            $signatureValue = $module->fetchSignature($processData->getDocId());
        } catch (\setasign\SetaPDF\Signer\Module\EidEasy\Exception $e) {
            echo 'Error on signing.';
            var_dump($e);
            echo '<hr/>If you want to restart the signature process click here: <a href="?reset=1">Restart</a>';
            break;
        }


        $reader = new SetaPDF_Core_Reader_File($file);
        $writer = new SetaPDF_Core_Writer_String();

        $document = SetaPDF_Core_Document::load($reader, $writer);
        $signer = new SetaPDF_Signer($document);
        $signer->saveSignature($processData->getTmpDocument(), $signatureValue);

        $_SESSION[__FILE__] = [
            'pdf' => [
                'name' => 'signed.pdf',
                'data' => $writer->getBuffer()
            ]
        ];

        echo 'The file was successfully signed. You can download the result <a href="?action=download" download="signed.pdf" target="_blank">here</a>.<hr/>'
            . ' If you want to restart the signature process click here: <a href="?reset=1">Restart</a>';
        break;

    case 'download':
        $doc = $_SESSION[__FILE__]['pdf'];

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $doc['name']);
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . strlen($doc['data']));
        echo $doc['data'];
        flush();
        break;
}
