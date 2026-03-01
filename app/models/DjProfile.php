<?php
//app/models/DjProfile.php
class DjProfile
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = db(); // from bootstrap
    }

    public function findByUserId(int $userId)
    {
        $stmt = $this->db->prepare("SELECT * FROM dj_profiles WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create(int $userId, string $slug)
    {
        $stmt = $this->db->prepare("
            INSERT INTO dj_profiles (user_id, page_slug)
            VALUES (?, ?)
        ");
        return $stmt->execute([$userId, $slug]);
    }

    public function update(int $id, array $data)
    {
$sql = "UPDATE dj_profiles SET
    display_name = :display_name,
    public_email = :public_email,
    phone = :phone,
    bio = :bio,
    social_spotify = :social_spotify,
    social_instagram = :social_instagram,
    social_facebook = :social_facebook,
    social_tiktok = :social_tiktok,
    social_youtube = :social_youtube,
    social_soundcloud = :social_soundcloud,
    city = :city,
    country = :country,
    logo_url = :logo_url,
    logo_focus_x = :logo_focus_x,
    logo_focus_y = :logo_focus_y,
    logo_zoom_pct = :logo_zoom_pct,
    show_logo_public_profile = :show_logo_public_profile,
    social_website = :social_website,
    theme_color = :theme_color,
    page_slug = :page_slug,
    is_public = :is_public
WHERE id = :id
";

        $stmt = $this->db->prepare($sql);

        $data['id'] = $id;

        return $stmt->execute($data);
    }

    public function ensureGalleryTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS dj_profile_gallery (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                image_url VARCHAR(255) NOT NULL,
                caption VARCHAR(160) NULL,
                sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_dj_profile_gallery_user (user_id),
                KEY idx_dj_profile_gallery_order (user_id, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function getGalleryByUserId(int $userId, bool $activeOnly = true): array
    {
        $this->ensureGalleryTable();

        $sql = "
            SELECT id, user_id, image_url, caption, sort_order, is_active
            FROM dj_profile_gallery
            WHERE user_id = :uid
        ";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function replaceGalleryForUser(int $userId, array $items): void
    {
        $this->ensureGalleryTable();

        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare("DELETE FROM dj_profile_gallery WHERE user_id = :uid");
            $del->execute(['uid' => $userId]);

            if (!empty($items)) {
                $ins = $this->db->prepare("
                    INSERT INTO dj_profile_gallery (user_id, image_url, caption, sort_order, is_active)
                    VALUES (:uid, :image_url, :caption, :sort_order, 1)
                ");

                foreach ($items as $idx => $item) {
                    $ins->execute([
                        'uid' => $userId,
                        'image_url' => (string)$item['image_url'],
                        'caption' => ($item['caption'] ?? null) !== '' ? (string)$item['caption'] : null,
                        'sort_order' => (int)$idx,
                    ]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
