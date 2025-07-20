import psycopg2
from psycopg2 import sql
try:
    from qgis.PyQt.QtCore import QObject, pyqtSignal
except ImportError:
    try:
        from PyQt6.QtCore import QObject, pyqtSignal
    except ImportError:
        from PyQt5.QtCore import QObject, pyqtSignal


class DatabaseManager(QObject):
    
    connectionSuccess = pyqtSignal(str)
    connectionError = pyqtSignal(str)
    queryFinished = pyqtSignal(list)
    queryError = pyqtSignal(str)
    autoConnected = pyqtSignal(dict)

    def __init__(self):
        super().__init__()
        self.connection = None
        self.connection_params = {}
        self.postgres_config = None

    def auto_connect_from_config(self, postgres_config):
        self.postgres_config = postgres_config
        
        if not postgres_config or not postgres_config.get('host'):
            self.connectionError.emit("No PostgreSQL configuration found")
            return False
            
        if not all([postgres_config.get('host'), postgres_config.get('database'), postgres_config.get('username')]):
            self.connectionError.emit("Incomplete PostgreSQL configuration")
            return False
        
        return self.connect(
            postgres_config['host'],
            postgres_config.get('port', 5432),
            postgres_config['database'],
            postgres_config['username'],
            postgres_config.get('password', '')
        )

    def test_connection(self, host, port, database, username, password):
        try:
            test_conn = psycopg2.connect(
                host=host,
                port=port,
                database=database,
                user=username,
                password=password,
                connect_timeout=10
            )
            
            cursor = test_conn.cursor()
            cursor.execute("SELECT version();")
            version = cursor.fetchone()[0]
            
            try:
                cursor.execute("SELECT PostGIS_version();")
                postgis_version = cursor.fetchone()[0]
                postgis_available = True
            except psycopg2.Error:
                postgis_version = "Not installed"
                postgis_available = False
            
            test_conn.close()
            
            success_msg = f"PostgreSQL: {version[:50]}...\nPostGIS: {postgis_version}"
            self.connectionSuccess.emit(success_msg)
            return True
            
        except psycopg2.Error as e:
            self.connectionError.emit(str(e))
            return False
        except Exception as e:
            self.connectionError.emit(f"Connection failed: {str(e)}")
            return False

    def connect(self, host, port, database, username, password):
        try:
            if self.connection:
                self.connection.close()
            
            self.connection = psycopg2.connect(
                host=host,
                port=port,
                database=database,
                user=username,
                password=password
            )
            
            self.connection_params = {
                'host': host,
                'port': port,
                'database': database,
                'username': username,
                'password': password
            }
            
            config_info = {
                'host': host,
                'database': database,
                'username': username,
                'status': 'connected'
            }
            
            self.autoConnected.emit(config_info)
            return True
            
        except psycopg2.Error as e:
            self.connectionError.emit(str(e))
            return False

    def disconnect(self):
        if self.connection:
            self.connection.close()
            self.connection = None
        self.connection_params = {}
        self.postgres_config = None

    def execute_sql(self, query, params=None):
        if not self.connection:
            self.queryError.emit("No database connection")
            return False
        
        try:
            cursor = self.connection.cursor()
            
            if params:
                cursor.execute(query, params)
            else:
                cursor.execute(query)
            
            self.connection.commit()
            
            if cursor.description:
                results = cursor.fetchall()
                self.queryFinished.emit(results)
                return results
            else:
                self.queryFinished.emit([])
                return True
                
        except psycopg2.Error as e:
            self.connection.rollback()
            self.queryError.emit(str(e))
            return False

    def create_table_from_sql(self, sql_script):
        if not self.connection:
            self.queryError.emit("No database connection")
            return False
        
        try:
            cursor = self.connection.cursor()
            cursor.execute(sql_script)
            self.connection.commit()
            self.queryFinished.emit([])
            return True
            
        except psycopg2.Error as e:
            self.connection.rollback()
            self.queryError.emit(str(e))
            return False

    def get_spatial_tables(self):
        if not self.connection:
            return []
        
        query = """
        SELECT 
            schemaname,
            tablename,
            attname as geom_column,
            type as geom_type
        FROM geometry_columns
        WHERE schemaname NOT IN ('information_schema', 'topology', 'tiger')
        ORDER BY schemaname, tablename;
        """
        
        try:
            cursor = self.connection.cursor()
            cursor.execute(query)
            results = cursor.fetchall()
            
            tables = []
            for row in results:
                tables.append({
                    'schema': row[0],
                    'table': row[1],
                    'geom_column': row[2],
                    'geom_type': row[3],
                    'full_name': f"{row[0]}.{row[1]}"
                })
            
            return tables
            
        except psycopg2.Error:
            return []

    def get_all_tables_with_geom(self):
        if not self.connection:
            return []
        
        query = """
        SELECT 
            t.table_schema,
            t.table_name,
            COALESCE(gc.f_geometry_column, 'geom') as geom_column,
            COALESCE(gc.type, 'UNKNOWN') as geom_type
        FROM information_schema.tables t
        LEFT JOIN geometry_columns gc ON (
            t.table_schema = gc.f_table_schema AND 
            t.table_name = gc.f_table_name
        )
        WHERE t.table_type = 'BASE TABLE'
        AND t.table_schema NOT IN ('information_schema', 'pg_catalog', 'topology', 'tiger')
        AND (
            gc.f_table_name IS NOT NULL OR
            EXISTS (
                SELECT 1 FROM information_schema.columns c
                WHERE c.table_schema = t.table_schema 
                AND c.table_name = t.table_name
                AND (c.data_type = 'USER-DEFINED' AND c.udt_name = 'geometry')
            )
        )
        ORDER BY t.table_schema, t.table_name;
        """
        
        try:
            cursor = self.connection.cursor()
            cursor.execute(query)
            results = cursor.fetchall()
            
            tables = []
            for row in results:
                tables.append({
                    'schema': row[0],
                    'table': row[1],
                    'geom_column': row[2],
                    'geom_type': row[3],
                    'full_name': f"{row[0]}.{row[1]}"
                })
            
            return tables
            
        except psycopg2.Error as e:
            print(f"Error getting tables: {e}")
            return []

    def get_survey_tables(self):
        if not self.connection:
            return []
        
        query = """
        SELECT 
            t.table_schema,
            t.table_name,
            COALESCE(gc.f_geometry_column, 'geom') as geom_column,
            COALESCE(gc.type, 'POINT') as geom_type
        FROM information_schema.tables t
        LEFT JOIN geometry_columns gc ON (
            t.table_schema = gc.f_table_schema AND 
            t.table_name = gc.f_table_name
        )
        WHERE t.table_type = 'BASE TABLE'
        AND t.table_name LIKE 'survey_arcgeek_%'
        ORDER BY t.table_schema, t.table_name;
        """
        
        try:
            cursor = self.connection.cursor()
            cursor.execute(query)
            results = cursor.fetchall()
            
            tables = []
            for row in results:
                tables.append({
                    'schema': row[0],
                    'table': row[1],
                    'geom_column': row[2],
                    'geom_type': row[3],
                    'full_name': f"{row[0]}.{row[1]}",
                    'is_survey': True
                })
            
            return tables
            
        except psycopg2.Error:
            return []

    def table_exists(self, table_name, schema='public'):
        if not self.connection:
            return False
        
        query = """
        SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = %s AND table_name = %s
        );
        """
        
        try:
            cursor = self.connection.cursor()
            cursor.execute(query, (schema, table_name))
            return cursor.fetchone()[0]
        except psycopg2.Error:
            return False

    def get_table_info(self, table_name, schema='public'):
        if not self.connection:
            return None
        
        query = """
        SELECT 
            column_name,
            data_type,
            is_nullable
        FROM information_schema.columns
        WHERE table_schema = %s AND table_name = %s
        ORDER BY ordinal_position;
        """
        
        try:
            cursor = self.connection.cursor()
            cursor.execute(query, (schema, table_name))
            results = cursor.fetchall()
            
            columns = []
            for row in results:
                columns.append({
                    'name': row[0],
                    'type': row[1],
                    'nullable': row[2] == 'YES'
                })
            
            return columns
            
        except psycopg2.Error:
            return None

    def get_connection_string(self):
        if not self.connection_params:
            return None
        
        return (
            f"host={self.connection_params['host']} "
            f"port={self.connection_params['port']} "
            f"dbname={self.connection_params['database']} "
            f"user={self.connection_params['username']} "
            f"password={self.connection_params['password']}"
        )

    def is_connected(self):
        return self.connection is not None
        
    def get_postgres_info(self):
        if not self.postgres_config:
            return None
        return {
            'host': self.postgres_config.get('host', ''),
            'port': self.postgres_config.get('port', 5432),
            'database': self.postgres_config.get('database', ''),
            'username': self.postgres_config.get('username', ''),
            'configured': bool(self.postgres_config.get('host'))
        }