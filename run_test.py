# run_test.py
import os
from crewai import Task, Crew
from agents.research_agent import research_agent
from dotenv import load_dotenv

# 1. Load the Iron Word (Environment Variables)
load_dotenv()

# 2. Define the Research Task
# This task enforces the SIE recursive loop: Internal check -> External search -> Synthesis
research_task = Task(
    description="""
    Research the latest developments in 'semantic depth' and its impact on SEO 
    from authoritative sources published in the last 6 months. 
    
    Your workflow MUST follow these steps:
    1. Query the internal Knowledge Core (Pinecone) to see our existing stance on semantic depth.
    2. Use the Web Search Tool to find supplemental or contrasting information from authoritative industry sources.
    3. Use the Content Scraper Tool to deep-read at least two high-quality articles found during search.
    
    Final Answer Requirement:
    A comprehensive, fact-based report in Markdown format. 
    Include:
    - A summary of current 'semantic depth' definitions for 2026.
    - Top 3 strategies for implementation.
    - A 'Gap Analysis' comparing our internal Knowledge Core findings against current external trends.
    - Citations with source URLs.
    """,
    agent=research_agent,
    expected_output="A detailed research report in markdown format, grounded in both internal and external intelligence."
)

# 3. Form the Crew
# We use a single-agent crew for this pilot phase of the Agent Loop.
sie_crew = Crew(
    agents=[research_agent],
    tasks=[research_task],
    verbose=True,
    memory=True # Matches the agent's memory capability
)

# 4. Kickoff the Loop
print("\n[SIE] Starting Research Agent Loop...")
print("[SIE] Root Protocol: The Bill Bernard Standard (Integrity as Strategy)\n")

result = sie_crew.kickoff()

# 5. Output the Findings
print("\n\n####################################")
print("## STRATEGIC INTELLIGENCE REPORT")
print("####################################\n")
print(result)

# Optional: You could later add code here to automatically save this to 
# your Obsidian SIE/09_Logs folder.