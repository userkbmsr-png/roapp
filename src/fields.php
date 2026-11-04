<?php
declare(strict_types=1);

function roapp_field_definitions(): array
{
    return [
        'roDisp' => [
            ['label' => 'Număr dispoziție', 'key' => 'numar'],
            ['label' => 'Data dispoziției (zz.ll.aaaa)', 'key' => 'data'],
            ['label' => 'Titlu', 'key' => 'titlu', 'large' => true],
            ['label' => 'Legislație / temeiuri', 'key' => 'legislatie', 'large' => true],
            ['label' => 'Articole / conținutul actului', 'key' => 'articole', 'large' => true],
            ['label' => 'Art. GDPR (câmp sursă)', 'key' => 'articole_grpd', 'large' => true],
            ['label' => 'Tip act', 'key' => 'tip'],
            ['label' => 'Număr proiect', 'key' => 'nr_proiect'],
            ['label' => 'Cod compartiment din arhivă', 'key' => 'cod_compartiment'],
            ['label' => 'Număr / dată referat', 'key' => 'nr_data_referat'],
            ['label' => 'Indicator legacy 11 (ex. referat / absențe)', 'key' => 'indicator_istoric_11'],
        ],
        'roHot' => [
            ['label' => 'Data ședinței (zz.ll.aaaa)', 'key' => 'data_sedinta'],
            ['label' => 'Ora ședinței', 'key' => 'ora'],
            ['label' => 'Tip ședință', 'key' => 'tip_sedinta'],
            ['label' => 'Număr proiect', 'key' => 'nr_proiect'],
            ['label' => 'Data proiectului (zz.ll.aaaa)', 'key' => 'data_proiect'],
            ['label' => 'Titlu proiect / hotărâre', 'key' => 'titlu', 'large' => true],
            ['label' => 'Cod inițiator din arhivă', 'key' => 'cod_initiator'],
            ['label' => 'Nume inițiator', 'key' => 'nume_initiator'],
            ['label' => 'Legislație / temeiuri', 'key' => 'legislatie', 'large' => true],
            ['label' => 'Articole / conținutul actului', 'key' => 'articole', 'large' => true],
            ['label' => 'Tip act', 'key' => 'tip_act'],
            ['label' => 'Art. GDPR (câmp sursă)', 'key' => 'articole_grpd', 'large' => true],
            ['label' => 'Cod raportor din arhivă', 'key' => 'cod_raportor'],
            ['label' => 'Raportor / nume', 'key' => 'nume_raportor'],
            ['label' => 'Număr raport', 'key' => 'nr_raport'],
            ['label' => 'Cod compartiment din arhivă', 'key' => 'cod_compartiment'],
            ['label' => 'Compartiment / referent', 'key' => 'nume_compartiment'],
            ['label' => 'Număr / dată referat', 'key' => 'nr_data_referat'],
            ['label' => 'Text referat', 'key' => 'referat', 'large' => true],
            ['label' => 'Anexe (câmp sursă)', 'key' => 'anexe', 'large' => true],
            ['label' => 'Cod comisie din arhivă', 'key' => 'cod_comisie'],
            ['label' => 'Comisie / președinte comisie', 'key' => 'comisie'],
            ['label' => 'Majoritate', 'key' => 'majoritate'],
            ['label' => 'Număr dispoziție de convocare', 'key' => 'nr_dispozitie'],
            ['label' => 'Ordinea de zi / convocator', 'key' => 'convocator', 'large' => true],
            ['label' => 'Număr hotărâre adoptată', 'key' => 'nr_hotarare'],
            ['label' => 'Președinte de ședință', 'key' => 'presedinte'],
            ['label' => 'Situația votului', 'key' => 'situatie_vot', 'large' => true],
        ],
    ];
}

function roapp_module_label(string $module): string
{
    return $module === 'roHot' ? 'Hotărâri de Consiliu Local' : 'Dispoziții ale Primarului';
}

function roapp_field_groups(string $module): array
{
    if ($module === 'roHot') {
        return [
            'Ședință și proiect' => [0, 1, 2, 3, 4, 5],
            'Inițiere și conținut' => [6, 7, 8, 9, 10, 11],
            'Rapoarte și referat' => [12, 13, 14, 15, 16, 17, 18, 19],
            'Comisie, adoptare și vot' => [20, 21, 22, 23, 24, 25, 26, 27],
        ];
    }
    return [
        'Datele dispoziției' => [0, 1, 2, 6, 7],
        'Conținut și temei' => [3, 4, 5],
        'Referat și câmpuri din arhivă' => [8, 9, 10],
    ];
}
