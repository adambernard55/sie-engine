# tools/wordpress_tool.py
from crewai.tools import tool
import requests
import os
from typing import Dict, Optional
from datetime import datetime

@tool("Update WordPress Post")
def update_wordpress_post(
    post_id: int,
    content: str,
    status: str = "draft",
    metadata: Optional[Dict] = None
) -> str:
    """
    Updates a WordPress post via REST API. Always saves as 'draft' by default
    to enforce human review (Rule G-04).
    
    Args:
        post_id: WordPress post ID to update.
        content: Full HTML or Markdown content of the post.
        status: Post status ('draft' or 'pending'). Must not be 'publish'.
        metadata: Optional dict with agent_confidence, reasoning, sources.
    
    Returns:
        Success message with post URL or error details.
    """
    if status == "publish":
        return "ERROR: Agents cannot publish directly. Set status='draft' and flag for human review."
    
    url = f"{os.getenv('WP_SITE_URL')}/wp-json/wp/v2/posts/{post_id}"
    auth = (os.getenv('WP_USERNAME'), os.getenv('WP_APP_PASSWORD'))
    
    payload = {
        'content': content,
        'status': status,
    }
    
    # Add agent metadata if provided
    if metadata:
        payload['meta'] = {
            'agent_modified': True,
            'agent_timestamp': datetime.now().isoformat(),
            'agent_confidence': metadata.get('confidence', 0.0),
            'agent_reasoning': metadata.get('reasoning', ''),
            'sources_used': metadata.get('sources', [])
        }
    
    try:
        response = requests.post(url, json=payload, auth=auth, timeout=30)
        response.raise_for_status()
        
        post_url = response.json().get('link', '')
        return f"✓ Post {post_id} updated successfully: {post_url}\nStatus: {status} (awaiting human review)"
    
    except requests.exceptions.RequestException as e:
        return f"✗ WordPress API Error for Post {post_id}: {str(e)}"