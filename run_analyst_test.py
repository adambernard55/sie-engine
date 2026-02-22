# run_analyst_test.py
from crewai import Task, Crew
from agents.analyst_agent import analyst_agent
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# Define the analysis task for the Analyst Agent
analysis_task = Task(
    description="""
    Analyze our Knowledge Core for articles related to 'content clustering.'
    
    Your final answer must be a comprehensive analysis that includes:
    1. A summary of our existing coverage on 'content clustering', based on a query of the Knowledge Core.
    2. A detailed report on knowledge gaps for this topic.
    3. A list of 3-5 potential internal links for a hypothetical new article on this topic.
    4. A content freshness assessment for a sample existing post (use Post ID 123 as a placeholder).
    """,
    agent=analyst_agent,
    expected_output="A detailed analysis report in markdown format."
)

# Form the crew, ensuring verbose is set to True (not 2)
crew = Crew(
    agents=[analyst_agent],
    tasks=[analysis_task],
    verbose=True
)

# Kick off the crew's work
print("--- Kicking off Analyst Crew ---")
result = crew.kickoff()

# Print the final result
print("\n\n########################")
print("## Analyst Report")
print("########################\n")
print(result)
