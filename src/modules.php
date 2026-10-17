<?php
declare(strict_types=1);

/*
 * Learning modules and their lesson ids. Keep in sync with the frontend's
 * src/data/modules.js — the API only accepts progress for ids listed here.
 */
const LEARNING_MODULES = [
    'savings' => ['why-save', 'set-goals', 'emergency-fund', 'pay-yourself-first', 'cut-costs', 'stay-on-track'],
    'budgeting' => ['what-is-a-budget', 'rule-50-30-20', 'track-spending', 'needs-vs-wants', 'realistic-goals'],
    'finlit' => ['foundations', 'spam', 'scam-mail', 'phishing', 'otp-fraud', 'card-fraud'],
];

function valid_module(string $module): bool
{
    return isset(LEARNING_MODULES[$module]);
}

function valid_lesson(string $module, string $lesson): bool
{
    return valid_module($module) && in_array($lesson, LEARNING_MODULES[$module], true);
}
