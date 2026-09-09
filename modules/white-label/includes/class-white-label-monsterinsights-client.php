<?php
/** Reuse MonsterInsights authentication/transport; never expose credentials to JS. */
if (!defined('ABSPATH')) {
    exit;
}

class White_Label_MonsterInsights_Client extends MonsterInsights_API_Reports
{
    protected $timeout = 10;

    public function query(array $body)
    {
        return $this->request('reporting/query', $body, 'POST');
    }
}
