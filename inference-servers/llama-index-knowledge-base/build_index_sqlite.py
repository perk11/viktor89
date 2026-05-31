import argparse
import os
import sqlite3

from llama_index.core import SimpleDirectoryReader

command_line_argument_parser = argparse.ArgumentParser(description="SQLite FTS5 index builder")
command_line_argument_parser.add_argument("documents_dir", type=str, help="Path to directory with documents")
command_line_argument_parser.add_argument("database_file_path", type=str, help="Path where to save SQLite database")
command_line_argument_parser.add_argument("base_url", type=str, help="Path where to link to")
parsed_command_line_arguments = command_line_argument_parser.parse_args()

loaded_directory_documents = SimpleDirectoryReader(
    parsed_command_line_arguments.documents_dir,
    recursive=True
).load_data()

absolute_documents_directory_path = os.path.abspath(parsed_command_line_arguments.documents_dir)

sqlite_database_connection = sqlite3.connect(parsed_command_line_arguments.database_file_path)
sqlite_database_cursor = sqlite_database_connection.cursor()

# FTS5 virtual tables lack standard column types and constraints; all columns behave as text
sqlite_database_cursor.execute("""
    CREATE VIRTUAL TABLE IF NOT EXISTS searchable_documents USING fts5(
        title, 
        url, 
        content
    )
""")
sqlite_database_connection.commit()

for current_document_instance in loaded_directory_documents:
    extracted_metadata_file_path = (
            current_document_instance.metadata.get("file_path") or
            current_document_instance.metadata.get("filepath") or
            current_document_instance.metadata.get("path")
    )

    if not isinstance(extracted_metadata_file_path, str) or not extracted_metadata_file_path:
        continue

    absolute_target_file_path = os.path.abspath(extracted_metadata_file_path)

    try:
        relative_target_file_path = os.path.relpath(
            absolute_target_file_path,
            start=absolute_documents_directory_path
        )
    except ValueError:
        continue

    formatted_relative_url_path = relative_target_file_path.replace(os.sep, "/").lstrip("/")
    constructed_public_document_url = f"{parsed_command_line_arguments.base_url}/{formatted_relative_url_path}"
    extracted_document_title = os.path.basename(absolute_target_file_path)

    sqlite_database_cursor.execute(
        """
        INSERT INTO searchable_documents (title, url, content)
        VALUES (?, ?, ?)
        """,
        (
            extracted_document_title,
            constructed_public_document_url,
            current_document_instance.text
        )
    )

sqlite_database_connection.commit()
sqlite_database_connection.close()
