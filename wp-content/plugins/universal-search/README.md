# Universal Multi-Modal Search (Typesense)

WordPress plugin that adds a multi-modal search (Text/Voice/Image) to the primary menu and queries Typesense.


## Install

1. Upload the plugin folder to `wp-content/plugins/` or install the provided ZIP from WP Admin → Plugins → Add New → Upload Plugin.
2. Inside the plugin directory, run:

   ```
   composer install
   ```
   (This installs `typesense/typesense-php`.)

3. Activate the plugin.

4. Configure **Settings → Universal Search**:
   - OpenAI API Key
   - Typesense: host, port, protocol, key, and collection

5. Make sure your theme registers a `primary` menu location. The search widget injects into that menu.

## Results Page

The plugin maps `/search-all/` to a simple template in `templates/results.php`.

## Notes

- Voice & Image requests call OpenAI (Whisper + GPT-4o-mini for intent extraction / vision).
- Text search can go directly via `/search-all/?q=...`.
- For indexing WordPress content to Typesense, create a `site_content` collection and sync posts/products via cron (not included).

