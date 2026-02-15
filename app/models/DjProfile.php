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
}