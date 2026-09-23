<?php
namespace GenZHype\Services;

use PDO;
use Throwable;

class VideoService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Consolidates logic from video_bridge.php (feed mode).
     * Generates a list of pending videos for the maker.
     */
    public function getPendingFeed(): array {
        $rows = $this->pdo->query("SELECT v.page_id, v.slug, v.title, v.hook, v.script, v.image, v.broll, v.shotlist, v.gravity, v.force_render, v.footage_clips
                                 FROM video_scripts v JOIN pages p ON p.id=v.page_id
                                 WHERE p.status='published' AND v.video_status='pending'
                                 ORDER BY v.force_render DESC, (v.shotlist IS NOT NULL) DESC, (v.tpl >= 2) DESC, v.created_at DESC
                                 LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

        $posts = [];
        foreach ($rows as $r) {
            $posts[] = $this->buildPost($r);
        }
        return $posts;
    }

    private function buildPost(array $row): array {
        // This would wrap the logic from video_feed_build_post()
        // For now, we return the row and would expand with the visual/people resolution logic
        return $row;
    }

    /**
     * Consolidates logic from video_bridge.php (ingest mode).
     * Handles the delivery of a finished video from the maker.
     */
    public function ingestVideo(int $pid, string $slug, string $mp4Path, array $meta): bool {
        // Idempotency check
        $row = $this->pdo->prepare("SELECT video_status, video_made_at FROM video_scripts WHERE page_id=?");
        $row->execute([$pid]);
        $cur = $row->fetch(PDO::FETCH_ASSOC);
        if ($cur && $cur['video_status'] === 'ready'
            && $cur['video_made_at'] && strtotime((string)$cur['video_made_at']) > filemtime($mp4Path)) {
            return false;
        }

        $relPath = '/media/video/' . $slug . '-' . $pid . '.mp4';
        $dest = dirname(__DIR__, 3) . '/public_html' . $relPath;

        if (!copy($mp4Path, $dest)) return false;
        chmod($dest, 0644);

        $stmt = $this->pdo->prepare("UPDATE video_scripts SET video_path=?, video_status='ready', video_made_at=NOW(), force_render=0 WHERE page_id=?");
        return $stmt->execute([$relPath, $pid]);
    }

    public function installTable(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS video_scripts (
          page_id       INT UNSIGNED PRIMARY KEY,
          slug          VARCHAR(200) NOT NULL,
          title         VARCHAR(300) NOT NULL,
          hook          VARCHAR(200) NOT NULL,
          script        MEDIUMTEXT   NOT NULL,
          image         VARCHAR(500) NOT NULL,
          video_path    VARCHAR(300) NULL,
          video_status  ENUM('pending','ready','skipped') NOT NULL DEFAULT 'pending',
          video_made_at DATETIME     NULL,
          created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          tpl           TINYINT NOT NULL DEFAULT 0,
          broll         VARCHAR(400) NULL,
          shotlist      MEDIUMTEXT NULL,
          gravity       VARCHAR(10) NOT NULL DEFAULT 'standard',
          skip_reason   VARCHAR(200) NULL,
          visual_plan   MEDIUMTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
