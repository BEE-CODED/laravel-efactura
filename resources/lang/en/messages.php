<?php

return [
    'processing_failed_at_anaf' => 'Processing failed at ANAF',
    'not_processed_at_anaf' => 'ANAF did not take in the submitted file (XML cu erori nepreluat de sistem). The document is not resubmitted automatically: correct it and resubmit it.',
    'upload_outcome_unknown' => 'ANAF\'s answer to the upload had no usable upload index or status, so it is not known whether the document was filed. It is not resubmitted automatically: check the SPV before resolving it.',
    'completed_without_download_id' => 'ANAF reported the invoice as accepted but gave no download id, so its response cannot be downloaded automatically. The invoice is filed; download the response from the SPV if you need it.',
    'status_nok_without_download_id' => 'ANAF answered the status check for upload index :index with an error and no download id. That is not a verdict on the document, so it is not known whether it was filed. It is not resubmitted automatically: check the SPV before resolving it.',
    'status_poll_failures_exhausted' => 'The status of upload index :index could not be read after :count attempts, so it is not known whether the document was filed. It is not resubmitted automatically: check the SPV before resolving it.',
];
