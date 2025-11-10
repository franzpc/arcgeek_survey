try:
    from qgis.PyQt.QtCore import QObject, pyqtSignal
    from qgis.PyQt.QtGui import QColor
except ImportError:
    try:
        from PyQt6.QtCore import QObject, pyqtSignal
        from PyQt6.QtGui import QColor
    except ImportError:
        from PyQt5.QtCore import QObject, pyqtSignal
        from PyQt5.QtGui import QColor

from qgis.core import (
    QgsVectorLayer, QgsProject, QgsDataSourceUri,
    QgsWkbTypes, QgsSymbol, QgsSingleSymbolRenderer,
    QgsFeature, QgsGeometry, QgsPointXY, QgsFields,
    QgsField, QgsMemoryProviderUtils
)
from qgis.PyQt.QtCore import QVariant


class LayerManager(QObject):
    
    layerAdded = pyqtSignal(str, str)
    layerError = pyqtSignal(str)
    layersRefreshed = pyqtSignal(list)
    freeLayersLoaded = pyqtSignal(list)

    def __init__(self, database_manager, api_client):
        super().__init__()
        self.db_manager = database_manager
        self.api_client = api_client
        self._forms_cache = {}
        self._forms_cache_timestamp = 0

    def get_available_layers(self):
        layers = []
        
        if self.db_manager.is_connected():
            try:
                postgres_layers = self.db_manager.get_survey_tables()
                forms_map = self._get_forms_map()
                
                for layer in postgres_layers:
                    layer['source'] = 'postgres'
                    table_name = layer['table']
                    
                    form_title = forms_map.get(table_name)
                    if not form_title:
                        form_title = self._extract_title_from_table_name(table_name)
                    
                    layer['form_title'] = form_title
                    layer['display_name'] = f"PostgreSQL: {layer['schema']}.{layer['table']} ({layer['geom_type']})"
                    layers.append(layer)
            except Exception as e:
                print(f"Error getting PostgreSQL layers: {e}")
        
        if self.api_client.is_authenticated:
            try:
                free_responses = self.api_client.get_free_responses()
                forms_map_free = self._get_forms_map_free()
                if free_responses:
                    form_titles = list(forms_map_free.keys())
                    title = form_titles[0] if form_titles else 'Free Plan Forms'
                    
                    free_layer = {
                        'schema': 'hosting',
                        'table': 'responses_free',
                        'geom_column': 'coordinates',
                        'geom_type': 'POINT',
                        'full_name': 'hosting.responses_free',
                        'source': 'hosting',
                        'form_title': title,
                        'display_name': f'ArcGeek Hosting: Free Plan Responses ({len(free_responses)} records)',
                        'count': len(free_responses)
                    }
                    layers.append(free_layer)
            except Exception as e:
                print(f"Error getting hosting layers: {e}")
        
        self.layersRefreshed.emit(layers)
        return layers

    def _get_forms_map(self):
        forms_map = {}
        if self.api_client.is_authenticated:
            try:
                import time
                current_time = time.time()
                
                if current_time - self._forms_cache_timestamp > 300:
                    self._forms_cache.clear()
                    self._forms_cache_timestamp = current_time
                
                if not self._forms_cache:
                    user_forms = self._get_user_forms_sync()
                    for form in user_forms:
                        table_name = form.get('table_name', '')
                        form_title = form.get('title', 'Unknown Form')
                        if table_name and table_name != 'responses_free':
                            self._forms_cache[table_name] = form_title
                
                forms_map = self._forms_cache.copy()
                
            except Exception as e:
                print(f"Error getting forms map: {e}")
        return forms_map

    def _get_forms_map_free(self):
        forms_map = {}
        if self.api_client.is_authenticated:
            try:
                user_forms = self._get_user_forms_sync()
                for form in user_forms:
                    table_name = form.get('table_name', '')
                    form_title = form.get('title', 'Unknown Form')
                    if table_name == 'responses_free':
                        forms_map[form_title] = table_name
            except Exception as e:
                print(f"Error getting free forms map: {e}")
        return forms_map

    def _get_user_forms_sync(self):
        try:
            if hasattr(self.api_client, 'get_user_forms_sync'):
                return self.api_client.get_user_forms_sync()
            else:
                return self.api_client.get_user_forms()
        except Exception as e:
            print(f"Error in _get_user_forms_sync: {e}")
            return []

    def _extract_title_from_table_name(self, table_name):
        if table_name.startswith('survey_arcgeek_'):
            number_part = table_name.replace('survey_arcgeek_', '')
            return f"Survey {number_part}"
        return table_name.replace('_', ' ').title()

    def add_layer_to_qgis(self, layer_info):
        if layer_info['source'] == 'postgres':
            return self._add_postgres_layer(layer_info)
        elif layer_info['source'] == 'hosting':
            return self._add_hosting_layer(layer_info)
        else:
            self.layerError.emit(f"Unknown layer source: {layer_info['source']}")
            return False

    def _add_postgres_layer(self, layer_info):
        if not self.db_manager.connection_params:
            self.layerError.emit("No database connection")
            return False
        
        try:
            uri = QgsDataSourceUri()
            uri.setConnection(
                self.db_manager.connection_params['host'],
                str(self.db_manager.connection_params['port']),
                self.db_manager.connection_params['database'],
                self.db_manager.connection_params['username'],
                self.db_manager.connection_params['password']
            )
            
            uri.setDataSource(
                layer_info['schema'],
                layer_info['table'],
                layer_info['geom_column']
            )
            
            layer_name = f"{layer_info['form_title']} ({layer_info['table']})"
            layer = QgsVectorLayer(uri.uri(), layer_name, "postgres")
            
            if not layer.isValid():
                self.layerError.emit(f"Invalid layer: {layer_name}")
                return False
            
            self._configure_layer_style(layer, layer_info['geom_type'])
            
            QgsProject.instance().addMapLayer(layer)
            
            self.layerAdded.emit(layer_name, layer_info['geom_type'])
            return True
            
        except Exception as e:
            self.layerError.emit(f"Error adding PostgreSQL layer: {str(e)}")
            return False

    def _add_hosting_layer(self, layer_info):
        if not self.api_client.is_authenticated:
            self.layerError.emit("Not authenticated")
            return False
        
        try:
            free_responses = self.api_client.get_free_responses()
            if not free_responses:
                self.layerError.emit("No free responses found")
                return False
            
            all_fields = set()
            for response in free_responses:
                data = response.get('data', {})
                if isinstance(data, dict):
                    all_fields.update(data.keys())
            
            fields = QgsFields()
            fields.append(QgsField("id", QVariant.String))
            fields.append(QgsField("form_title", QVariant.String))
            fields.append(QgsField("form_code", QVariant.String))
            fields.append(QgsField("accuracy", QVariant.Double))
            fields.append(QgsField("created_at", QVariant.String))
            
            for field_name in sorted(all_fields):
                clean_name = field_name.replace(' ', '_').lower()
                fields.append(QgsField(clean_name, QVariant.String))
            
            layer_name = f"{layer_info['form_title']} (Free Responses)"
            layer = QgsMemoryProviderUtils.createMemoryLayer(
                layer_name,
                fields,
                QgsWkbTypes.Point,
                QgsProject.instance().crs()
            )
            
            if not layer.isValid():
                self.layerError.emit("Could not create memory layer")
                return False
            
            features = []
            for response in free_responses:
                if response.get('latitude') and response.get('longitude'):
                    feature = QgsFeature(fields)
                    feature.setGeometry(QgsGeometry.fromPointXY(
                        QgsPointXY(float(response['longitude']), float(response['latitude']))
                    ))
                    
                    attributes = [
                        response.get('unique_display_id', ''),
                        response.get('form_title', ''),
                        response.get('form_code', ''),
                        float(response.get('accuracy', 0)),
                        response.get('created_at', '')
                    ]
                    
                    data = response.get('data', {})
                    if isinstance(data, dict):
                        for field_name in sorted(all_fields):
                            attributes.append(str(data.get(field_name, '')))
                    else:
                        for _ in all_fields:
                            attributes.append('')
                    
                    feature.setAttributes(attributes)
                    features.append(feature)
            
            layer.dataProvider().addFeatures(features)
            layer.updateExtents()
            
            self._configure_layer_style(layer, 'POINT')
            
            QgsProject.instance().addMapLayer(layer)
            
            self.layerAdded.emit(layer_name, 'POINT')
            return True
            
        except Exception as e:
            self.layerError.emit(f"Error adding hosting layer: {str(e)}")
            return False

    def add_multiple_layers(self, layers_info):
        added_count = 0
        
        for layer_info in layers_info:
            if self.add_layer_to_qgis(layer_info):
                added_count += 1
        
        return added_count

    def refresh_survey_layers(self):
        survey_layers = []
        
        if self.db_manager.is_connected():
            postgres_surveys = self.db_manager.get_survey_tables()
            forms_map = self._get_forms_map()
            for layer in postgres_surveys:
                layer['source'] = 'postgres'
                table_name = layer['table']
                form_title = forms_map.get(table_name)
                if not form_title:
                    form_title = self._extract_title_from_table_name(table_name)
                layer['form_title'] = form_title
                survey_layers.append(layer)
        
        if self.api_client.is_authenticated:
            free_responses = self.api_client.get_free_responses()
            forms_map_free = self._get_forms_map_free()
            if free_responses:
                form_titles = list(forms_map_free.keys())
                title = form_titles[0] if form_titles else 'Free Plan Responses'
                free_layer = {
                    'schema': 'hosting',
                    'table': 'responses_free',
                    'geom_column': 'geom',
                    'geom_type': 'POINT',
                    'full_name': 'hosting.responses_free',
                    'source': 'hosting',
                    'is_survey': True,
                    'form_title': title,
                    'display_name': title
                }
                survey_layers.append(free_layer)
        
        return survey_layers

    def get_layer_statistics(self, layer_info):
        if layer_info['source'] == 'postgres':
            return self._get_postgres_layer_stats(layer_info)
        elif layer_info['source'] == 'hosting':
            return self._get_hosting_layer_stats(layer_info)
        else:
            return None

    def _get_postgres_layer_stats(self, layer_info):
        if not self.db_manager.connection:
            return None
        
        table_name = f"{layer_info['schema']}.{layer_info['table']}"
        
        queries = {
            'total_records': f"SELECT COUNT(*) FROM {table_name};",
            'with_geometry': f"SELECT COUNT(*) FROM {table_name} WHERE {layer_info['geom_column']} IS NOT NULL;",
            'last_update': f"SELECT MAX(created_at) FROM {table_name};"
        }
        
        stats = {}
        
        try:
            for key, query in queries.items():
                result = self.db_manager.execute_sql(query)
                if result and len(result) > 0:
                    stats[key] = result[0][0]
                else:
                    stats[key] = 0
            
            return stats
            
        except Exception:
            return None

    def _get_hosting_layer_stats(self, layer_info):
        if not self.api_client.is_authenticated:
            return None
        
        try:
            free_responses = self.api_client.get_free_responses()
            
            total_records = len(free_responses)
            with_geometry = len([r for r in free_responses if r.get('latitude') and r.get('longitude')])
            
            last_update = None
            if free_responses:
                dates = [r.get('created_at') for r in free_responses if r.get('created_at')]
                if dates:
                    last_update = max(dates)
            
            return {
                'total_records': total_records,
                'with_geometry': with_geometry,
                'last_update': last_update
            }
            
        except Exception:
            return None

    def _configure_layer_style(self, layer, geom_type):
        geom_type_lower = geom_type.lower()
        
        if 'point' in geom_type_lower:
            color = QColor(255, 0, 0, 180)
            symbol = QgsSymbol.defaultSymbol(QgsWkbTypes.PointGeometry)
            symbol.setColor(color)
            symbol.setSize(4)
            
        elif 'line' in geom_type_lower:
            color = QColor(0, 255, 0, 180)
            symbol = QgsSymbol.defaultSymbol(QgsWkbTypes.LineGeometry)
            symbol.setColor(color)
            symbol.setWidth(2)
            
        elif 'polygon' in geom_type_lower:
            color = QColor(0, 0, 255, 100)
            symbol = QgsSymbol.defaultSymbol(QgsWkbTypes.PolygonGeometry)
            symbol.setColor(color)
            
        else:
            return
        
        renderer = QgsSingleSymbolRenderer(symbol)
        layer.setRenderer(renderer)
        layer.triggerRepaint()

    def remove_layer_from_qgis(self, layer_name):
        layers = QgsProject.instance().mapLayersByName(layer_name)
        
        for layer in layers:
            QgsProject.instance().removeMapLayer(layer.id())
        
        return len(layers) > 0

    def get_all_survey_layers(self):
        all_layers = []
        
        if self.db_manager.is_connected():
            postgres_layers = self.db_manager.get_survey_tables()
            forms_map = self._get_forms_map()
            for layer in postgres_layers:
                layer['source'] = 'postgres'
                table_name = layer['table']
                form_title = forms_map.get(table_name)
                if not form_title:
                    form_title = self._extract_title_from_table_name(table_name)
                layer['form_title'] = form_title
                all_layers.append(layer)
        
        if self.api_client.is_authenticated:
            free_responses = self.api_client.get_free_responses()
            forms_map_free = self._get_forms_map_free()
            if free_responses:
                form_titles = list(forms_map_free.keys())
                title = form_titles[0] if form_titles else 'Free Plan Responses'
                free_layer = {
                    'schema': 'hosting',
                    'table': 'responses_free',
                    'geom_column': 'geom',
                    'geom_type': 'POINT',
                    'full_name': 'hosting.responses_free',
                    'source': 'hosting',
                    'is_survey': True,
                    'form_title': title,
                    'display_name': title,
                    'count': len(free_responses)
                }
                all_layers.append(free_layer)
        
        return all_layers

    def refresh_all_layers(self):
        return self.get_available_layers()

    def get_layer_source_info(self, layer_info):
        if layer_info['source'] == 'postgres':
            postgres_info = self.db_manager.get_postgres_info()
            if postgres_info:
                return f"PostgreSQL: {postgres_info['host']}/{postgres_info['database']}"
            return "PostgreSQL: Not connected"
        elif layer_info['source'] == 'hosting':
            return f"ArcGeek Hosting: {self.api_client.base_url}"
        else:
            return "Unknown source"

    def can_add_layers(self):
        return self.db_manager.is_connected() or self.api_client.is_authenticated

    def get_connection_status(self):
        return {
            'postgres': self.db_manager.is_connected(),
            'hosting': self.api_client.is_authenticated,
            'can_add_layers': self.can_add_layers()
        }