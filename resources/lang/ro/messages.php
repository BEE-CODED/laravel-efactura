<?php

return [
    'processing_failed_at_anaf' => 'Procesarea la ANAF a eșuat',
    'not_processed_at_anaf' => 'ANAF nu a preluat fișierul transmis (XML cu erori nepreluat de sistem). Documentul nu este retransmis automat: corectați-l și retransmiteți-l.',
    'upload_outcome_unknown' => 'Răspunsul ANAF la încărcare nu a conținut un index de încărcare sau o stare utilizabilă, deci nu se știe dacă documentul a fost depus. Nu este retransmis automat: verificați în SPV înainte de a-l rezolva.',
    'upload_outcome_no_answer' => 'Încărcarea poate să fi ajuns la ANAF, dar nu s-a primit un răspuns utilizabil la ea, deci nu se știe dacă documentul a fost depus. Nu este retransmis automat: verificați în SPV înainte de a-l rezolva.',
    'completed_without_download_id' => 'ANAF a raportat factura ca acceptată, dar nu a transmis un id de descărcare, deci răspunsul nu poate fi descărcat automat. Factura este depusă; descărcați răspunsul din SPV dacă aveți nevoie de el.',
    'status_nok_without_download_id' => 'ANAF a răspuns la verificarea stării pentru indexul de încărcare :index fără un verdict asupra documentului (o eroare sau nok fără id de descărcare), deci nu se știe dacă a fost depus. Nu este retransmis automat: verificați în SPV înainte de a-l rezolva.',
    'status_poll_failures_exhausted' => 'Starea indexului de încărcare :index nu a putut fi citită după :count încercări, deci nu se știe dacă documentul a fost depus. Nu este retransmis automat: verificați în SPV înainte de a-l rezolva.',
    'local_failure' => 'O eroare locală (:class) a oprit acest pas; detaliile sunt în jurnalul aplicației.',
];
