# tools/pinecone_tool.py

# crewai is only needed when this module is used as an agent tool.
# kb_sync.py uses the raw functions directly, so we degrade gracefully.
try:
    from crewai.tools import tool
except ImportError:
    def tool(func_or_name=None):  # no-op fallback handles @tool and @tool("name")
        if callable(func_or_name):
            return func_or_name
        def decorator(func):
            return func
        return decorator

from pinecone import Pinecone
from openai import OpenAI
import os
import re
import yaml
from pathlib import Path

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


def chunk_document(
    title: str,
    body: str,
    semantic_summary: str = "",
    synthetic_questions: list = None,
    key_concepts: list = None,
    tags: list = None
) -> list[dict]:
    """Split a document into section-level chunks for embedding.

    Each chunk carries a shared preamble (title, summary, concepts, tags,
    questions) so every vector retains document-level context, plus the
    section-specific text for focused retrieval.

    Returns a list of dicts with keys:
        chunk_index, section_header, content (for embedding), raw_text (metadata)
    """
    synthetic_questions = synthetic_questions or []
    key_concepts = key_concepts or []
    tags = tags or []

    # Build preamble — shared context prepended to every chunk
    preamble_parts = [title]
    if semantic_summary:
        preamble_parts.append(semantic_summary)
    if key_concepts:
        preamble_parts.append("Key concepts: " + ", ".join(key_concepts))
    if tags:
        preamble_parts.append("Tags: " + ", ".join(tags))
    if synthetic_questions:
        preamble_parts.append("Questions this answers: " + " | ".join(synthetic_questions))
    preamble = "\n".join(preamble_parts) + "\n\n"

    # Split on H2 headers
    parts = re.split(r'^(## .+)$', body, flags=re.MULTILINE)

    chunks = []

    # Text before first ## header → chunk 0 ("Introduction")
    intro_text = parts[0].strip()
    if intro_text:
        chunks.append({
            "chunk_index": 0,
            "section_header": "Introduction",
            "content": preamble + intro_text,
            "raw_text": intro_text,
        })

    # Iterate header/body pairs (re.split with capture group alternates header, body)
    for i in range(1, len(parts), 2):
        header = parts[i].strip()
        section_body = parts[i + 1].strip() if i + 1 < len(parts) else ""
        if not section_body:
            continue
        section_text = f"{header}\n{section_body}"
        chunks.append({
            "chunk_index": len(chunks),
            "section_header": header.lstrip("# ").strip(),
            "content": preamble + section_text,
            "raw_text": section_text,
        })

    # Fallback: no H2 headers → single chunk with full body
    if not chunks and body.strip():
        chunks.append({
            "chunk_index": 0,
            "section_header": "",
            "content": preamble + body.strip(),
            "raw_text": body.strip(),
        })

    return chunks


# =============================================================================
# Search Configuration
# =============================================================================

_search_config_cache = None


def _load_search_config() -> dict:
    """Load search settings from config.yaml with sensible defaults."""
    global _search_config_cache
    if _search_config_cache is not None:
        return _search_config_cache

    defaults = {"top_k": 5, "high_threshold": 0.70, "low_threshold": 0.55}

    for candidate in [
        Path(__file__).parent.parent / "config.yaml",
        Path.cwd() / "config.yaml",
    ]:
        if candidate.exists():
            try:
                with open(candidate, encoding="utf-8") as fh:
                    cfg = yaml.safe_load(fh) or {}
                search_cfg = cfg.get("search", {})
                defaults.update({k: v for k, v in search_cfg.items() if v is not None})
            except Exception:
                pass
            break

    _search_config_cache = defaults
    return defaults


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
        cfg = _load_search_config()
        top_k = cfg["top_k"]
        high_threshold = cfg["high_threshold"]
        low_threshold = cfg["low_threshold"]

        index = _get_index()
        query_embedding = _generate_embedding(query)

        # Search Pinecone with config-driven top_k
        results = index.query(
            vector=query_embedding,
            top_k=top_k,
            include_metadata=True
        )

        if not results.matches or len(results.matches) == 0:
            return f"Knowledge Core Search: No existing intelligence found on '{query}'. This is new territory - proceed with external research."

        # ── Dedup by document ────────────────────────────────────────────
        # Chunk IDs look like "kb-123-chunk-2"; legacy IDs are "kb-123".
        # Keep only the highest-scoring chunk per base document.
        best_per_doc: dict = {}  # base_id → match
        for match in results.matches:
            base_id = match.id.rsplit("-chunk-", 1)[0]
            if base_id not in best_per_doc or match.score > best_per_doc[base_id].score:
                best_per_doc[base_id] = match

        # ── Tiered results ───────────────────────────────────────────────
        high_findings = []
        medium_findings = []

        for match in best_per_doc.values():
            score = match.score
            metadata = match.metadata
            if score < low_threshold:
                continue

            content = metadata.get("text", "No content available")
            source = metadata.get("source", "Internal research")
            date = metadata.get("date", "Unknown date")
            title = metadata.get("title", "")
            section = metadata.get("section_header", "")

            # Build display label: Title > Section (Score: 0.85)
            label_parts = [title] if title else []
            if section:
                label_parts.append(section)
            label = " > ".join(label_parts) if label_parts else source
            header = f"**{label} (Score: {score:.2f})**"

            entry = f"{header}\n{content}\nSource: {source} | Date: {date}"

            if score >= high_threshold:
                high_findings.append(entry)
            else:
                medium_findings.append(entry)

        if not high_findings and not medium_findings:
            return f"Knowledge Core Search: Found {len(results.matches)} matches for '{query}' but none above confidence threshold ({low_threshold}). Recommend fresh external research."

        parts = []
        if high_findings:
            parts.append("**High-Confidence Results:**\n\n" + "\n\n---\n\n".join(high_findings))
        if medium_findings:
            parts.append("**Medium-Confidence Results:**\n\n" + "\n\n---\n\n".join(medium_findings))

        return "**Knowledge Core Intelligence Found:**\n\n" + "\n\n".join(parts)

    except Exception as e:
        return f"Knowledge Core unavailable: {str(e)}. Proceeding with external search only."


def upsert_to_knowledge_core(
    content: str,
    doc_id: str,
    title: str = "",
    source: str = "",
    date: str = "",
    post_type: str = "knowledge_base",
    url: str = "",
    tags: list = None,
    key_concepts: list = None,
    synthetic_questions: list = None,
    section_header: str = "",
    chunk_index: int = -1,
    total_chunks: int = 1
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
        tags: List of document tags
        key_concepts: List of key concepts
        synthetic_questions: List of synthetic questions the document answers
        section_header: H2 section header for chunked vectors
        chunk_index: Index of this chunk within the document (-1 = unchunked)
        total_chunks: Total number of chunks for this document

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
            "url": url,
            "tags": ",".join(tags) if tags else "",
            "key_concepts": ",".join(key_concepts) if key_concepts else "",
            "synthetic_questions": " | ".join(synthetic_questions) if synthetic_questions else "",
            "section_header": section_header,
            "chunk_index": chunk_index,
            "total_chunks": total_chunks,
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
                        "url": doc.get("url", ""),
                        "tags": ",".join(doc.get("tags") or []),
                        "key_concepts": ",".join(doc.get("key_concepts") or []),
                        "synthetic_questions": " | ".join(doc.get("synthetic_questions") or []),
                        "section_header": doc.get("section_header", ""),
                        "chunk_index": doc.get("chunk_index", -1),
                        "total_chunks": doc.get("total_chunks", 1),
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