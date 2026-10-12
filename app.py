
import streamlit as st

st.set_page_config(
    page_title="Smart Waste Collection",
    page_icon="♻️",
    layout="wide"
)

st.title("♻️ Smart Waste Collection")
st.write("Smart Waste Collection and Management System")

st.subheader("Project Dashboard")

col1, col2, col3 = st.columns(3)

col1.metric("Pending Requests", "Demo")
col2.metric("Active Workers", "Demo")
col3.metric("Collection Plans", "Demo")

st.info(
    "Welcome! Connect your PHP backend and "
    "MySQL database to display live project data."
)
