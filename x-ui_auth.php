<?php

function xuiPanelApiToken(array $panel)
{
    if (!isset($panel['api_token']) || !is_scalar($panel['api_token'])) {
        return null;
    }

    $token = trim((string) $panel['api_token']);
    return $token === '' ? null : $token;
}

function xuiUsesBearerAuth(array $panel)
{
    return xuiPanelApiToken($panel) !== null;
}
