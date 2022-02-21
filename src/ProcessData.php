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
    protected $docId;

    /**
     * @var SetaPDF_Signer_TmpDocument
     */
    protected $tmpDocument;

    /**
     * @var string
     */
    protected $fieldName;

    public function __construct(
        string $docId, SetaPDF_Signer_TmpDocument $tmpDocument, string $fieldName
    ) {
        $this->docId = $docId;
        $this->tmpDocument = $tmpDocument;
        $this->fieldName = $fieldName;
    }

    public function getDocId(): string
    {
        return $this->docId;
    }

    public function getTmpDocument(): SetaPDF_Signer_TmpDocument
    {
        return $this->tmpDocument;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }
}
