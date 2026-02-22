# run_editor_test.py
from crewai import Task, Crew
from agents.editor_agent import editor_agent
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# IMPORTANT: Change this to a real Post ID of a draft post in your WordPress site
TEST_POST_ID = 23355 

# Define the editing task for the Editor Agent
editing_task = Task(
    description=f"""
    Take the following bullet-point outline and expand it into a 500-word article.
    The target post ID for this article is {TEST_POST_ID}.

    Outline:
    - Introduction to Agentic Workflows
    - The role of the Research Agent
    - The role of the Analyst Agent
    - The role of the Editor Agent
    - How they collaborate in a crew to create strategic intelligence

    Your final steps must be executed in this order:
    1. Generate the full article content based on the outline.
    2. Use the 'Schema Validator' tool to confirm your work is compliant.
    3. Use the 'Internal Link Inserter' tool with a mock list of suggestions: ['Semantic Depth', 'Knowledge Core Architecture'].
    4. Use the 'Update WordPress Post' tool to save the final, complete content to Post ID {TEST_POST_ID} with a 'draft' status.
    """,
    agent=editor_agent,
    expected_output="A confirmation message from the 'Update WordPress Post' tool indicating a successful update."
)

# Form the crew
crew = Crew(
    agents=[editor_agent],
    tasks=[editing_task],
    verbose=True # Use True for standard logging, or 2 for more detailed logs
)

# Kick off the crew's work
print("--- Kicking off Editor Crew ---")
result = crew.kickoff()

# Print the final result
print("\n\n########################")
print("## Editor Task Report")
print("########################\n")
print(result)