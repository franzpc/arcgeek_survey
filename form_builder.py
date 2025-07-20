import re
import random
try:
    from qgis.PyQt.QtCore import QObject, pyqtSignal
except ImportError:
    try:
        from PyQt6.QtCore import QObject, pyqtSignal
    except ImportError:
        from PyQt5.QtCore import QObject, pyqtSignal


class FormBuilder(QObject):
    
    sqlGenerated = pyqtSignal(str)
    formCreated = pyqtSignal(dict)
    error = pyqtSignal(str)
    formValidated = pyqtSignal(dict)

    def __init__(self):
        super().__init__()

    def generate_table_name(self, form_title):
        clean = re.sub(r'[^a-zA-Z0-9]', '_', form_title.lower())
        clean = clean[:15].strip('_')
        if not clean:
            clean = 'form'
        
        counter = f"{random.randint(1, 99999):05d}"
        return f'survey_arcgeek_{counter}'

    def validate_fields(self, fields):
        if not fields:
            return False, "At least one field is required"
        
        if len(fields) > 15:
            return False, "Maximum 15 fields allowed"
        
        field_names = []
        for field in fields:
            if not field.get('name'):
                return False, "Field name is required"
            
            if not field.get('type'):
                return False, "Field type is required"
            
            name = field['name'].lower().strip()
            if name in field_names:
                return False, f"Duplicate field name: {name}"
            
            field_names.append(name)
            
            if not re.match(r'^[a-zA-Z][a-zA-Z0-9_]*$', name):
                return False, f"Invalid field name: {name}"
        
        return True, "OK"

    def prepare_fields_for_api(self, fields):
        api_fields = []
        
        for field in fields:
            field_config = {
                'name': field['name'].lower().strip(),
                'label': field.get('label', field['name']),
                'type': field['type'],
                'required': field.get('required', False)
            }
            api_fields.append(field_config)
        
        return api_fields

    def generate_spatial_sql(self, table_name, fields, form_title=""):
        valid, msg = self.validate_fields(fields)
        if not valid:
            self.error.emit(msg)
            return None
        
        columns = [
            "id SERIAL PRIMARY KEY",
            "unique_display_id VARCHAR(20) UNIQUE",
            "latitude DECIMAL(10,8)",
            "longitude DECIMAL(11,8)",
            "gps_accuracy DECIMAL(10,2)",
            "geom GEOMETRY(POINT, 4326)",
            "created_at TIMESTAMP DEFAULT NOW()",
            "ip_address INET"
        ]
        
        for field in fields:
            field_name = field['name'].lower().strip()
            field_type = field['type']
            
            sql_type = self._get_sql_type(field_type)
            columns.append(f"{field_name} {sql_type}")
        
        sql_parts = []
        
        sql_parts.append(f"-- ArcGeek Survey Table: {form_title}")
        sql_parts.append(f"-- Generated: {self._get_timestamp()}")
        sql_parts.append("")
        
        create_table = f"CREATE TABLE {table_name} (\n  " + ",\n  ".join(columns) + "\n);"
        sql_parts.append(create_table)
        sql_parts.append("")
        
        indexes = [
            f"CREATE INDEX idx_{table_name}_geom ON {table_name} USING GIST (geom);",
            f"CREATE INDEX idx_{table_name}_coords ON {table_name} (latitude, longitude);",
            f"CREATE UNIQUE INDEX idx_{table_name}_display_id ON {table_name} (unique_display_id);"
        ]
        
        for index in indexes:
            sql_parts.append(index)
        
        sql_parts.append("")
        
        trigger_function = f"""CREATE OR REPLACE FUNCTION update_{table_name}_geom()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.latitude IS NOT NULL AND NEW.longitude IS NOT NULL THEN
        NEW.geom := ST_SetSRID(ST_MakePoint(NEW.longitude, NEW.latitude), 4326);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;"""
        
        sql_parts.append(trigger_function)
        sql_parts.append("")
        
        trigger = f"""CREATE TRIGGER trigger_{table_name}_geom
    BEFORE INSERT OR UPDATE ON {table_name}
    FOR EACH ROW
    EXECUTE FUNCTION update_{table_name}_geom();"""
        
        sql_parts.append(trigger)
        
        final_sql = "\n".join(sql_parts)
        self.sqlGenerated.emit(final_sql)
        return final_sql

    def create_form_package(self, title, description, fields, user_plan, can_use_postgres=False):
        valid, msg = self.validate_fields(fields)
        if not valid:
            self.error.emit(msg)
            return None
        
        api_fields = self.prepare_fields_for_api(fields)
        
        if user_plan == 'free' or not can_use_postgres:
            form_data = {
                'title': title.strip(),
                'description': description.strip(),
                'fields': api_fields,
                'table_name': 'responses_free',
                'creation_type': 'free'
            }
        else:
            table_name = self.generate_table_name(title)
            sql = self.generate_spatial_sql(table_name, fields, title)
            
            if not sql:
                return None
            
            form_data = {
                'title': title.strip(),
                'description': description.strip(),
                'fields': api_fields,
                'table_name': table_name,
                'sql': sql,
                'creation_type': 'postgres'
            }
        
        package = {
            'form_data': form_data,
            'user_plan': user_plan,
            'can_use_postgres': can_use_postgres
        }
        
        validation_result = {
            'valid': True,
            'fields_count': len(api_fields),
            'table_name': form_data['table_name'],
            'creation_type': form_data['creation_type']
        }
        
        self.formValidated.emit(validation_result)
        self.formCreated.emit(package)
        return package

    def _get_sql_type(self, field_type):
        type_mapping = {
            'text': 'VARCHAR(255)',
            'email': 'VARCHAR(255)',
            'number': 'DECIMAL(10,2)',
            'textarea': 'TEXT',
            'date': 'DATE',
            'url': 'VARCHAR(500)',
            'tel': 'VARCHAR(20)'
        }
        return type_mapping.get(field_type, 'VARCHAR(255)')

    def _get_timestamp(self):
        from datetime import datetime
        return datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    def validate_form_data(self, title, fields, max_fields=15):
        if not title.strip():
            return False, "Form title is required"
        
        if not fields:
            return False, "At least one field is required"
        
        if len(fields) > max_fields:
            return False, f"Maximum {max_fields} fields allowed"
        
        return self.validate_fields(fields)

    def get_field_limits(self, plan_type):
        limits = {
            'free': {'forms': 1, 'fields': 5, 'responses': 40},
            'basic': {'forms': 5, 'fields': 15, 'responses': 300},
            'premium': {'forms': -1, 'fields': 15, 'responses': 1000}
        }
        return limits.get(plan_type, limits['free'])