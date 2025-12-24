<?php
/**
 * Image Handler
 *
 * Handles product image uploads and management
 */

namespace Siater2026\Product;

defined('ABSPATH') || exit;

use Siater2026\Core\Settings;
use Siater2026\Utils\Logger;

class ImageHandler {

    /**
     * Settings instance
     */
    private Settings $settings;

    /**
     * Logger instance
     */
    private Logger $logger;

    /**
     * Constructor
     */
    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->logger = Logger::instance();
    }

    /**
     * Set product images (main + gallery)
     */
    public function set_product_images(int $product_id, array $data): void {
        $main_image = $data['foto'] ?? '';
        $gallery = $data['gallery'] ?? [];

        // Upload main image
        if (!empty($main_image) && $this->is_valid_image_url($main_image)) {
            $attachment_id = $this->upload_image($main_image, $product_id);
            if ($attachment_id) {
                set_post_thumbnail($product_id, $attachment_id);
            }
        }

        // Upload gallery images
        $gallery_ids = [];
        foreach ($gallery as $image_url) {
            if (!empty($image_url) && $this->is_valid_image_url($image_url)) {
                $attachment_id = $this->upload_image($image_url, $product_id);
                if ($attachment_id) {
                    $gallery_ids[] = $attachment_id;
                }
            }
        }

        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    /**
     * Set variation image
     */
    public function set_variation_image(int $variation_id, array $data): void {
        $variation_images = $data['variation_images'] ?? [];

        // Find first valid image
        foreach ($variation_images as $image_url) {
            if (!empty($image_url) && $this->is_valid_image_url($image_url)) {
                $attachment_id = $this->upload_image($image_url, $variation_id);
                if ($attachment_id) {
                    update_post_meta($variation_id, '_thumbnail_id', $attachment_id);
                    return;
                }
            }
        }
    }

    /**
     * Upload image to media library
     *
     * @param string $url Image URL
     * @param int $post_id Associated post ID
     * @return int|false Attachment ID or false
     */
    public function upload_image(string $url, int $post_id): int|false {
        // Get filename from URL
        $filename = $this->get_filename_from_url($url);

        if (!$filename) {
            return false;
        }

        // Check if already in media library
        $existing = $this->find_in_media($filename);
        if ($existing) {
            return $existing;
        }

        // Download image
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download to temp
        $temp_file = download_url($url, 30);

        if (is_wp_error($temp_file)) {
            $this->logger->warning("Failed to download image: $url - " . $temp_file->get_error_message());
            return false;
        }

        // Prepare file array
        $file_array = [
            'name' => $filename,
            'tmp_name' => $temp_file,
        ];

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, $post_id);

        // Clean up temp file
        if (file_exists($temp_file)) {
            @unlink($temp_file);
        }

        if (is_wp_error($attachment_id)) {
            $this->logger->warning("Failed to upload image: $url - " . $attachment_id->get_error_message());
            return false;
        }

        return $attachment_id;
    }

    /**
     * Check if URL is a valid image
     */
    private function is_valid_image_url(string $url): bool {
        if (empty($url)) {
            return false;
        }

        // Skip placeholder images
        if (strpos($url, 'img_non_disponibile') !== false) {
            return false;
        }

        // Check for valid image extension
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $path = parse_url($url, PHP_URL_PATH);

        if (!$path) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, $valid_extensions);
    }

    /**
     * Get filename from URL
     */
    private function get_filename_from_url(string $url): ?string {
        $path = parse_url($url, PHP_URL_PATH);

        if (!$path) {
            return null;
        }

        $filename = basename($path);

        // Sanitize filename
        $filename = sanitize_file_name($filename);

        return $filename ?: null;
    }

    /**
     * Find image in media library by filename
     */
    private function find_in_media(string $filename): int|false {
        global $wpdb;

        // Remove extension for search
        $name = pathinfo($filename, PATHINFO_FILENAME);

        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                AND post_title = %s
                ORDER BY ID DESC
                LIMIT 1",
                $name
            )
        );

        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * Delete product images
     */
    public function delete_product_images(int $product_id): void {
        // Delete featured image
        $thumbnail_id = get_post_thumbnail_id($product_id);
        if ($thumbnail_id) {
            wp_delete_attachment($thumbnail_id, true);
        }

        // Delete gallery images
        $gallery = get_post_meta($product_id, '_product_image_gallery', true);
        if ($gallery) {
            $gallery_ids = explode(',', $gallery);
            foreach ($gallery_ids as $attachment_id) {
                wp_delete_attachment($attachment_id, true);
            }
        }

        delete_post_thumbnail($product_id);
        delete_post_meta($product_id, '_product_image_gallery');
    }
}
