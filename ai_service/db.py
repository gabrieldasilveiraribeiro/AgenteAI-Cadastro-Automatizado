# -*- coding: utf-8 -*-


import mysql.connector
from mysql.connector import Error
import settings


def get_conn():

    try:
        conn = mysql.connector.connect(
            host=settings.DB_HOST,
            user=settings.DB_USER,
            password=settings.DB_PASS,
            database=settings.DB_NAME,
            charset="utf8mb4",
            use_unicode=True,
            autocommit=False
        )

        if conn.is_connected():
            cursor = conn.cursor()
            cursor.execute("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;")
            cursor.execute("SET CHARACTER SET utf8mb4;")
            cursor.execute("SET character_set_connection=utf8mb4;")
            cursor.close()
        return conn

    except Error as e:
        print(f"Erro ao conectar ao banco de dados: {e}")
        raise


if __name__ == "__main__":
    try:
        connection = get_conn()
        print("&#9989; Conexao estabelecida com sucesso:", connection)
        connection.close()
    except Exception as ex:
        print("&#10060; Falha:", ex)
