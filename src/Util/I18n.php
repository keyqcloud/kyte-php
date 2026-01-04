<?php

namespace Kyte\Util;

/**
 * Internationalization (i18n) Helper
 *
 * Provides translation support for multiple languages.
 * Supports: English (en), Japanese (ja), Spanish (es), Korean (ko)
 *
 * Usage:
 *   I18n::setLanguage('ja');
 *   echo I18n::t('error.not_found');  // "レコードが見つかりません"
 *   echo I18n::t('success.created', ['model' => 'User']);  // "User created successfully"
 *
 * @package Kyte\Util
 * @version 4.0.0
 */
class I18n
{
    /**
     * Current active language
     * @var string
     */
    private static $currentLanguage = 'en';

    /**
     * Loaded translations for current language
     * @var array
     */
    private static $translations = [];

    /**
     * Supported languages
     * @var array
     */
    private static $supportedLanguages = ['en', 'ja', 'es', 'ko'];

    /**
     * Translation file cache (prevents reloading)
     * @var array
     */
    private static $loadedLanguages = [];

    /**
     * Set the current language
     *
     * @param string $lang Language code (en, ja, es, ko)
     * @return bool True if language set successfully, false if unsupported
     */
    public static function setLanguage($lang)
    {
        // Validate language code
        if (!in_array($lang, self::$supportedLanguages)) {
            error_log("I18n: Unsupported language '$lang', falling back to 'en'");
            $lang = 'en';
        }

        // Only reload if language changed
        if ($lang !== self::$currentLanguage) {
            self::$currentLanguage = $lang;
            self::loadTranslations($lang);
        }

        return true;
    }

    /**
     * Get current language
     *
     * @return string Current language code
     */
    public static function getCurrentLanguage()
    {
        return self::$currentLanguage;
    }

    /**
     * Get supported languages
     *
     * @return array List of supported language codes
     */
    public static function getSupportedLanguages()
    {
        return self::$supportedLanguages;
    }

    /**
     * Translate a key
     *
     * @param string $key Translation key (e.g., 'error.not_found')
     * @param array $params Parameters to substitute (e.g., ['model' => 'User'])
     * @return string Translated string with parameters substituted
     */
    public static function t($key, $params = [])
    {
        // Get translation or fallback to key
        $text = self::$translations[$key] ?? $key;

        // Substitute parameters if provided
        if (!empty($params)) {
            foreach ($params as $paramKey => $paramValue) {
                $text = str_replace('{' . $paramKey . '}', $paramValue, $text);
            }
        }

        return $text;
    }

    /**
     * Check if a translation key exists
     *
     * @param string $key Translation key
     * @return bool True if translation exists
     */
    public static function has($key)
    {
        return isset(self::$translations[$key]);
    }

    /**
     * Detect language from Accept-Language header
     *
     * @param string|null $acceptLanguage Accept-Language header value
     * @return string Detected language code (en, ja, es, ko)
     */
    public static function detectLanguageFromHeader($acceptLanguage = null)
    {
        // Use provided header or get from server
        if ($acceptLanguage === null) {
            $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
        }

        // Parse Accept-Language header (e.g., "ja,en-US;q=0.9,en;q=0.8")
        $languages = explode(',', $acceptLanguage);

        foreach ($languages as $lang) {
            // Remove quality value (;q=0.9)
            $lang = explode(';', $lang)[0];

            // Get primary language code (en-US -> en)
            $langCode = explode('-', trim($lang))[0];

            // Return first supported language
            if (in_array($langCode, self::$supportedLanguages)) {
                return $langCode;
            }
        }

        // Default to English
        return 'en';
    }

    /**
     * Load translations from file
     *
     * @param string $lang Language code
     * @return void
     */
    private static function loadTranslations($lang)
    {
        // Check if already loaded (cache)
        if (isset(self::$loadedLanguages[$lang])) {
            self::$translations = self::$loadedLanguages[$lang];
            return;
        }

        // Build translation file path
        $translationFile = __DIR__ . '/../../translations/' . $lang . '.php';

        // Load translation file
        if (file_exists($translationFile)) {
            $translations = include $translationFile;

            if (is_array($translations)) {
                self::$translations = $translations;
                self::$loadedLanguages[$lang] = $translations; // Cache
            } else {
                error_log("I18n: Translation file '{$translationFile}' did not return an array");
                self::$translations = [];
            }
        } else {
            // Translation file not found, use English as fallback
            if ($lang !== 'en') {
                error_log("I18n: Translation file not found: {$translationFile}, falling back to English");
                self::loadTranslations('en');
            } else {
                self::$translations = [];
            }
        }
    }

    /**
     * Clear translation cache (useful for testing)
     *
     * @return void
     */
    public static function clearCache()
    {
        self::$loadedLanguages = [];
        self::$translations = [];
    }

    /**
     * Add custom translations at runtime (for testing or extensions)
     *
     * @param array $translations Associative array of key => translation
     * @return void
     */
    public static function addTranslations(array $translations)
    {
        self::$translations = array_merge(self::$translations, $translations);
    }
}
