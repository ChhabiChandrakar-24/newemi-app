<?php
$company = App\Models\Company::withTrashed()->find(16);
var_dump($company ? $company->status : "Not found");
