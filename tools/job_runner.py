"""
SIE Agent Job Runner — polls WordPress for queued jobs and dispatches CrewAI agents.

Usage:
    python -m tools.job_runner              # Poll once
    python -m tools.job_runner --watch      # Poll every 30s
    python -m tools.job_runner --watch 10   # Poll every 10s

REST API endpoints (require manage_options capability):
    GET  /wp-json/sie/v1/agent-jobs/next       — claim next queued job
    POST /wp-json/sie/v1/agent-jobs/{id}       — update status/result
    GET  /wp-json/sie/v1/agent-jobs            — list all jobs
"""

import os
import sys
import time
import json
import argparse
import requests
from dotenv import load_dotenv

load_dotenv()

WP_SITE_URL  = os.getenv('WP_SITE_URL', '').rstrip('/')
WP_USERNAME  = os.getenv('WP_USERNAME', '')
WP_APP_PASS  = os.getenv('WP_APP_PASSWORD', '')

API_BASE = f"{WP_SITE_URL}/wp-json/sie/v1/agent-jobs"


def wp_auth():
    """Return requests auth tuple."""
    return (WP_USERNAME, WP_APP_PASS)


def fetch_next_job():
    """Claim the next queued job from WordPress."""
    try:
        r = requests.get(f"{API_BASE}/next", auth=wp_auth(), timeout=15)
        r.raise_for_status()
        data = r.json()
        return data if data else None
    except Exception as e:
        print(f"[job_runner] Error fetching job: {e}")
        return None


def update_job(job_id: str, status: str, result: str = None):
    """Update a job's status and optional result."""
    payload = {'status': status}
    if result is not None:
        payload['result'] = result
    try:
        r = requests.post(f"{API_BASE}/{job_id}", auth=wp_auth(), json=payload, timeout=15)
        r.raise_for_status()
        return r.json()
    except Exception as e:
        print(f"[job_runner] Error updating job {job_id}: {e}")
        return None


def dispatch_job(job: dict) -> str:
    """
    Route the job to the appropriate CrewAI agent and return the result.

    This is the integration point — each agent/task combo maps to a CrewAI
    crew invocation. As agents are built out, add cases here.
    """
    agent_key = job.get('agent', '')
    task_key  = job.get('task', '')
    user_input = job.get('input', '')

    print(f"[job_runner] Dispatching: agent={agent_key} task={task_key}")
    print(f"[job_runner] Input: {user_input[:200]}")

    # -------------------------------------------------------------------
    # Route to CrewAI agents
    # -------------------------------------------------------------------

    if agent_key == 'research' and task_key == 'research_topic':
        return run_research_agent(user_input)

    elif agent_key == 'analyst' and task_key == 'analyze_coverage':
        return run_analyst_agent(user_input, mode='coverage')

    elif agent_key == 'analyst' and task_key == 'content_audit':
        return run_analyst_agent(user_input, mode='audit')

    elif agent_key == 'editor' and task_key == 'generate_article':
        return run_editor_agent(user_input, mode='generate')

    elif agent_key == 'editor' and task_key == 'improve_content':
        return run_editor_agent(user_input, mode='improve')

    else:
        return f"Unknown agent/task combination: {agent_key}/{task_key}"


# ---------------------------------------------------------------------------
# CrewAI Agent Wrappers
# ---------------------------------------------------------------------------

def run_research_agent(topic: str) -> str:
    """Run the research agent on a topic."""
    try:
        from crewai import Crew, Task
        from agents.research_agent import research_agent

        task = Task(
            description=(
                f"Research the following topic thoroughly:\n\n{topic}\n\n"
                "Steps:\n"
                "1. Query the Knowledge Core (Pinecone) to check existing coverage\n"
                "2. Conduct web research to find external sources\n"
                "3. Deep-read at least 2 high-quality articles\n"
                "4. Synthesize findings into a comprehensive report\n"
                "5. Identify gaps in existing knowledge base coverage"
            ),
            agent=research_agent,
            expected_output="A structured markdown report with findings, citations, and gap analysis.",
        )

        crew = Crew(agents=[research_agent], tasks=[task], verbose=True, memory=True)
        result = crew.kickoff()
        return str(result)

    except ImportError as e:
        return f"CrewAI not available: {e}"
    except Exception as e:
        return f"Research agent error: {e}"


def run_analyst_agent(topic: str, mode: str = 'coverage') -> str:
    """Run the analyst agent."""
    try:
        from crewai import Crew, Task
        from agents.analyst_agent import analyst_agent

        if mode == 'audit':
            desc = (
                f"Audit the following area of the knowledge base:\n\n{topic}\n\n"
                "Steps:\n"
                "1. Query the Knowledge Core for existing content\n"
                "2. Check for outdated information and broken references\n"
                "3. Identify missing metadata and incomplete articles\n"
                "4. Assess overall content quality\n"
                "5. Produce an actionable audit report with priorities"
            )
        else:
            desc = (
                f"Analyze knowledge base coverage for:\n\n{topic}\n\n"
                "Steps:\n"
                "1. Query the Knowledge Core to assess current coverage\n"
                "2. Identify knowledge gaps and missing subtopics\n"
                "3. Suggest internal link opportunities\n"
                "4. Assess content freshness\n"
                "5. Provide strategic recommendations for content priorities"
            )

        task = Task(
            description=desc,
            agent=analyst_agent,
            expected_output="A structured analysis report with findings and recommendations.",
        )

        crew = Crew(agents=[analyst_agent], tasks=[task], verbose=True)
        result = crew.kickoff()
        return str(result)

    except ImportError as e:
        return f"CrewAI not available: {e}"
    except Exception as e:
        return f"Analyst agent error: {e}"


def run_editor_agent(instructions: str, mode: str = 'generate') -> str:
    """Run the editor agent."""
    try:
        from crewai import Crew, Task
        from agents.editor_agent import editor_agent

        if mode == 'improve':
            desc = (
                f"Improve existing content based on these instructions:\n\n{instructions}\n\n"
                "Steps:\n"
                "1. Read the current content (Post ID if provided)\n"
                "2. Enhance structure, clarity, and completeness\n"
                "3. Add relevant citations from the knowledge base\n"
                "4. Optimize for SEO (Rank Math fields)\n"
                "5. Save as draft in WordPress for human review"
            )
        else:
            desc = (
                f"Generate a new article based on this outline/brief:\n\n{instructions}\n\n"
                "Steps:\n"
                "1. Expand the outline into a full article\n"
                "2. Validate against SIE content schema\n"
                "3. Insert relevant internal links\n"
                "4. Save as draft in WordPress for human review"
            )

        task = Task(
            description=desc,
            agent=editor_agent,
            expected_output="Confirmation of WordPress draft creation with post URL.",
        )

        crew = Crew(agents=[editor_agent], tasks=[task], verbose=True)
        result = crew.kickoff()
        return str(result)

    except ImportError as e:
        return f"CrewAI not available: {e}"
    except Exception as e:
        return f"Editor agent error: {e}"


# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------

def poll_once():
    """Poll for one job, execute it, report results."""
    job = fetch_next_job()
    if not job:
        return False

    job_id = job['id']
    print(f"\n[job_runner] === Picked up job: {job_id} ===")

    try:
        result = dispatch_job(job)
        update_job(job_id, 'completed', result)
        print(f"[job_runner] === Job {job_id} completed ===")
    except Exception as e:
        update_job(job_id, 'failed', str(e))
        print(f"[job_runner] === Job {job_id} failed: {e} ===")

    return True


def main():
    parser = argparse.ArgumentParser(description='SIE Agent Job Runner')
    parser.add_argument('--watch', nargs='?', const=30, type=int,
                        help='Poll interval in seconds (default: 30)')
    args = parser.parse_args()

    if not WP_SITE_URL or not WP_USERNAME or not WP_APP_PASS:
        print("[job_runner] Error: WP_SITE_URL, WP_USERNAME, and WP_APP_PASSWORD must be set in .env")
        sys.exit(1)

    print(f"[job_runner] Connected to: {WP_SITE_URL}")

    if args.watch:
        print(f"[job_runner] Watch mode: polling every {args.watch}s")
        while True:
            poll_once()
            time.sleep(args.watch)
    else:
        if not poll_once():
            print("[job_runner] No queued jobs found.")


if __name__ == '__main__':
    main()
