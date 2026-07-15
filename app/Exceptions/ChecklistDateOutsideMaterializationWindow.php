<?php

namespace App\Exceptions;

use RuntimeException;

class ChecklistDateOutsideMaterializationWindow extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Senarai semak hanya boleh dicipta dalam tempoh 365 hari dari hari ini.');
    }
}
