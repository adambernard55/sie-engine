# tools/pinecone_tool.py
from crewai.tools import tool
from pinecone import Pinecone
from openai import OpenAI
import os

# Initialize clients (lazy loading to avoid issues when env not loaded)
_pc = None
_openai_client = None


def _get_pinecone():
    global _pc
    if _pc is None:
        _pc = Pinecone(api_key=os.getenv("PINECONE_API_KEY"))
    return _pc


def _get_openai():
    global _openai_client
    if _openai_client is None:
        _openai_client = OpenAI(api_key=os.getenv("OPENAI_API_KEY"))
    return _openai_client


def _get_index():
    index_name = os.getenv("PINECONE_INDEX_NAME", "adambernard")
    return _get_pinecone().Index(index_name)


def _generate_embedding(text: str) -> list:
    """Generate embedding using text-embedding-3-small (512 dimensions)"""
    response = _get_openai().embeddings.create(
        model="text-embedding-3-small",
        input=text,
        dimensions=512
    )
    return response.data[0].embedding


# =============================================================================
# Core Functions (callable directly from Python)
# =============================================================================

def search_knowledge_core(query: str) -> str:
    """
    Search the SIE Knowledge Core (Pinecone) for existing intelligence.
    Use this FIRST before conducting external web searches to leverage
    internal institutional knowledge and avoid redundant research.

    Args:
        query: The research question or topic to search for

    Returns:
        Relevant findings from the Knowledge Core, or indication if no prior intelligence exists
    """
    try:
        index = _get_index()
        query_embedding = _generate_embedding(query)

        # Search Pinecone
        results = index.query(
            vector=query_embedding,
            top_k=3,
            include_metadata=True
        )
        
        # Format results
        if not results.matches or len(results.matches) == 0:
            return f"Knowledge Core Search: No existing intelligence found on '{query}'. This is new territory - proceed with external research."
        
        # Extract relevant findings
        findings = []
        for match in results.matches:
            score = match.score
            metadata = match.metadata
            
            # Only include high-confidence matches (similarity > 0.7)
            if score > 0.7:
                content = metadata.get('text', 'No content available')
                source = metadata.get('source', 'Internal research')
                date = metadata.get('date', 'Unknown date')
                
                findings.append(f"**Match Score: {score:.2f}**\n{content}\nSource: {source} | Date: {date}")
        
        if not findings:
            return f"Knowledge Core Search: Found {len(results.matches)} low-confidence matches for '{query}'. Recommend fresh external research."
        
        return f"**Knowledge Core Intelligence Found:**\n\n" + "\n\n---\n\n".join(findings)
        
    except Exception as e:
        return f"Knowledge Core unavailable: {str(e)}. Proceeding with external search only."


def upsert_to_knowledge_core(
    content: str,
    doc_id: str,
    title: str = "",
    source: str = "",
    date: str = "",
    post_type: str = "knowledge_base",
    url: str = ""
) -> str:
    """
    Add or update a document in the SIE Knowledge Core (Pinecone).
    Use this after creating/updating WordPress content to keep the vector database in sync.

    Args:
        content: The text content to embed and store
        doc_id: Unique identifier (e.g., WordPress post ID or slug)
        title: Document title
        source: Source reference (e.g., WordPress URL)
        date: Document date (ISO format preferred)
        post_type: WordPress post type (default: knowledge_base)
        url: Full URL to the content

    Returns:
        Success message with upsert details, or error message
    """
    try:
        index = _get_index()
        embedding = _generate_embedding(content)

        # Prepare metadata
        metadata = {
            "text": content[:8000],  # Pinecone metadata limit
            "title": title,
            "source": source or url,
            "date": date,
            "post_type": post_type,
            "url": url
        }

        # Upsert to Pinecone
        result = index.upsert(vectors=[{
            "id": str(doc_id),
            "values": embedding,
            "metadata": metadata
        }])

        return f"Knowledge Core updated: '{title}' (ID: {doc_id}) - {result.get('upserted_count', 1)} vector(s) upserted"

    except Exception as e:
        return f"Knowledge Core upsert failed: {str(e)}"


def batch_upsert_to_knowledge_core(documents: list) -> dict:
    """
    Batch upsert multiple documents to the Knowledge Core.
    More efficient than individual upserts for syncing multiple posts.

    Args:
        documents: List of dicts with keys: content, doc_id, title, source, date, post_type, url

    Returns:
        Dict with success_count, failed_count, and errors list
    """
    try:
        index = _get_index()
        vectors = []
        errors = []

        for doc in documents:
            try:
                embedding = _generate_embedding(doc["content"])

                vectors.append({
                    "id": str(doc["doc_id"]),
                    "values": embedding,
                    "metadata": {
                        "text": doc["content"][:8000],
                        "title": doc.get("title", ""),
                        "source": doc.get("source", doc.get("url", "")),
                        "date": doc.get("date", ""),
                        "post_type": doc.get("post_type", "knowledge_base"),
                        "url": doc.get("url", "")
                    }
                })
            except Exception as e:
                errors.append({"doc_id": doc.get("doc_id"), "error": str(e)})

        # Batch upsert (Pinecone handles up to 100 vectors per request)
        success_count = 0
        for i in range(0, len(vectors), 100):
            batch = vectors[i:i+100]
            result = index.upsert(vectors=batch)
            success_count += result.get("upserted_count", len(batch))

        return {
            "success_count": success_count,
            "failed_count": len(errors),
            "errors": errors
        }

    except Exception as e:
        return {
            "success_count": 0,
            "failed_count": len(documents),
            "errors": [{"error": str(e)}]
        }


def delete_from_knowledge_core(doc_ids: list) -> str:
    """
    Delete documents from the Knowledge Core by ID.
    Use when WordPress posts are deleted to keep the vector database in sync.

    Args:
        doc_ids: List of document IDs to delete

    Returns:
        Success or error message
    """
    try:
        index = _get_index()
        index.delete(ids=[str(id) for id in doc_ids])
        return f"Knowledge Core: Deleted {len(doc_ids)} document(s)"

    except Exception as e:
        return f"Knowledge Core delete failed: {str(e)}"


# =============================================================================
# CrewAI Tool Wrappers (for agent usage)
# =============================================================================

@tool("Knowledge Core Search")
def query_knowledge_core(query: str) -> str:
    """
    Search the SIE Knowledge Core (Pinecone) for existing intelligence.
    Use this FIRST before conducting external web searches to leverage
    internal institutional knowledge and avoid redundant research.

    Args:
        query: The research question or topic to search for

    Returns:
        Relevant findings from the Knowledge Core, or indication if no prior intelligence exists
    """
    return search_knowledge_core(query)


@tool("Knowledge Core Upsert")
def upsert_knowledge_core_tool(
    content: str,
    doc_id: str,
    title: str = "",
    source: str = "",
    date: str = "",
    url: str = ""
) -> str:
    """
    Add or update a document in the SIE Knowledge Core (Pinecone).
    Use this after creating/updating WordPress content to keep the vector database in sync.

    Args:
        content: The text content to embed and store
        doc_id: Unique identifier (e.g., WordPress post ID or slug)
        title: Document title
        source: Source reference (e.g., WordPress URL)
        date: Document date (ISO format preferred)
        url: Full URL to the content

    Returns:
        Success message with upsert details, or error message
    """
    return upsert_to_knowledge_core(
        content=content,
        doc_id=doc_id,
        title=title,
        source=source,
        date=date,
        url=url
    )