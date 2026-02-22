# tools/content_scraping_tool.py
from crewai.tools import tool
from firecrawl import FirecrawlApp
import os
from dotenv import load_dotenv

# Load environment variables from the .env file
load_dotenv()

@tool("Content Scraper Tool")
def scrape_url(url: str) -> str:
    """
    Scrapes a URL to extract its main content in a clean, readable format.
    Use this to read the content of a specific webpage found during a search.
    
    Args:
        url: The URL to scrape.
        
    Returns:
        The cleaned markdown content of the page.
    """
    # Initialize Firecrawl with the API key from your .env
    api_key = os.getenv("FIRECRAWL_API_KEY")
    
    if not api_key:
        return "Error: FIRECRAWL_API_KEY not found in environment variables."
        
    app = FirecrawlApp(api_key=api_key)
    
    try:
        # Updated method call - Firecrawl SDK uses .scrape() not .scrape_url()
        result = app.scrape(url)
        
        # Extract markdown content from result
        if isinstance(result, dict):
            markdown = result.get('markdown') or result.get('content') or result.get('text')
            if markdown:
                return markdown
            return f"Error: No markdown content found in response. Keys available: {list(result.keys())}"
        
        return str(result)
    
    except Exception as e:
        return f"Error occurred while scraping the URL: {str(e)}"