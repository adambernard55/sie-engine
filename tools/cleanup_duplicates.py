"""Delete duplicate posts created during aborted sync."""
import os
from dotenv import load_dotenv
load_dotenv()

import requests
from requests.auth import HTTPBasicAuth

WP_SITE_URL = os.getenv("WP_SITE_URL", "https://adambernard.com")
WP_USERNAME = os.getenv("WP_USERNAME")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD")

auth = HTTPBasicAuth(WP_USERNAME, WP_APP_PASSWORD)

# Delete the duplicate posts created during aborted sync
for post_id in [24703, 24704, 24705, 24706, 24707]:
    url = f"{WP_SITE_URL}/wp-json/wp/v2/knowledge/{post_id}?force=true"
    resp = requests.delete(url, auth=auth)
    print(f"Delete {post_id}: {resp.status_code}")
