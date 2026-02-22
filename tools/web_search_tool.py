# tools/web_search_tool.py
from crewai.tools import SerperDevTool
import os

# Initialize web search tool using SerperDev API
web_search = SerperDevTool()
