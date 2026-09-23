<?php
namespace GenZHype\Services;

use PDO;

class TermService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Consolidates logic from term_data.php.
     * Fetches popularity metrics for a term.
     */
    public function getPopularityData(string $term, int $days = 90): ?array {
        // Here we would move the Wikipedia/Wiktionary logic from term_data.php
        // For now, this acts as the professional wrapper.
        return null; // Logic to be migrated from term_data.php
    }

    public function getStatusFromData(array $data): string {
        return [
            'rising'  => 'peaking',
            'falling' => 'fading',
            'steady'  => 'mainstream',
        ][$data['direction']] ?? 'mainstream';
    }
}
