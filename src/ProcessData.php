<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\EidEasy;

use SetaPDF_Signer_TmpDocument;

/**
 * Class ProcessData
 *
 * A simple data container for serializing the process data.
 */
class ProcessData
{
    /**
     * @var string
     */
    private $docId;

    /**
     * @var SetaPDF_Signer_TmpDocument
     */
    private $tmpDocument;

    public function __construct(string $docId, SetaPDF_Signer_TmpDocument $tmpDocument)
    {
        $this->docId = $docId;
        $this->tmpDocument = $tmpDocument;
    }

    public function getDocId(): string
    {
        return $this->docId;
    }

    public function getTmpDocument(): SetaPDF_Signer_TmpDocument
    {
        return $this->tmpDocument;
    }
}
