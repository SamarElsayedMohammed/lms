import json
import glob

files = glob.glob('**/*.postman_collection.json', recursive=True)
for file in files:
    with open(file, 'r', encoding='utf-8') as f:
        data = json.load(f)
        
    modified = False

    def process_item(item):
        global modified
        if 'item' in item:
            for sub in item['item']:
                process_item(sub)
        elif 'request' in item:
            req = item['request']
            url = req.get('url', {})
            path = []
            if isinstance(url, dict):
                path = url.get('path', [])
            elif isinstance(url, str):
                path = url.split('/')
            
            # Check if user-login is in the path
            if any('user-login' in str(p) for p in path):
                body = req.get('body', {})
                if body.get('mode') == 'raw':
                    raw_str = body.get('raw', '{}')
                    try:
                        raw_json = json.loads(raw_str)
                        if 'type' not in raw_json:
                            raw_json['type'] = 'email'
                            modified = True
                        if 'firebase_token' not in raw_json:
                            raw_json['firebase_token'] = 'dummy_token_or_empty'
                            modified = True
                        
                        body['raw'] = json.dumps(raw_json, indent=4)
                    except Exception as e:
                        print(f"Could not parse body in {file} for {item.get('name')}")

    if 'item' in data:
        for item in data['item']:
            process_item(item)
            
    if modified:
        with open(file, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=4)
        print(f"Updated {file}")

