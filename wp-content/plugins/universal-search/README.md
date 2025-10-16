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

## Local Testing Checklist

Follow these steps to verify the Typesense integration, the instant dropdown, and the `/search-all/` results page locally.

1. **Start the containers.**
   - From the repository root run `docker compose up -d` to start WordPress/MySQL.
   - Launch Typesense with the compose snippet supplied in the project brief (or `docker run` using the same options). The default credentials in the plugin settings assume:
     - Host: `typesense`
     - Port: `8108`
     - Protocol: `http`
     - API Key: `eSiSArntEnTinEQuOunCutaIGEtoReag`

2. **Install WooCommerce sample data (optional but recommended).**
   - Enter the WordPress container: `docker compose exec wordpress bash`.
   - Install WooCommerce if it is not present: `wp plugin install woocommerce --activate`.
   - Import demo products: `wp wc product create --name="Sample Product" --regular_price=9.99` (repeat as needed) or use the WooCommerce sample data importer in the dashboard.

3. **Activate and configure the plugin.**
   - In WP Admin go to **Plugins → Universal Multi-Modal Search** and ensure it is active.
   - Navigate to **Settings → Universal Search** and fill out:
     - Typesense host/protocol/port/key (see step 1).
     - Choose the post types you wish to index (e.g. `post`, `product`).
     - Save to trigger a background re-sync.

4. **Trigger an initial re-index (optional, speeds up testing).**
   - Inside the WordPress container run `wp cron event run univ_search_sync_all` to immediately sync existing content.
   - Verify the collection in Typesense contains documents: `curl http://typesense:8108/collections/site_content/documents/search -H "X-TYPESENSE-API-KEY: eSiSArntEnTinEQuOunCutaIGEtoReag" -G --data-urlencode "q=*" --data-urlencode "query_by=title"`.

5. **Test the front end.**
   - Visit `http://localhost:8081` and use the primary navigation search bar.
   - Typing should show the instant dropdown; clicking **View more results** should redirect to `/search-all/` with full listings, product imagery, and prices for WooCommerce items.

6. **Regression checks.**
   - Create, update, and delete posts/products in WordPress and confirm Typesense reflects the changes (e.g. repeat the `curl` search or use the dashboard search).
   - Switch the indexed post types in **Settings → Universal Search**, save, and ensure removed types disappear from Typesense results.

## Notes

- Voice & Image requests call OpenAI (Whisper + GPT-4o-mini for intent extraction / vision).
- Text search can go directly via `/search-all/?q=...`.
- Background indexing relies on WP-Cron; running `wp cron event run univ_search_sync_all` forces an immediate re-sync when testing.

