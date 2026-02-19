
import csv
import json
import re
import sys

# Configuration
CSV_FILE = '/Users/alearuca/Downloads/product_export_2026-02-18-05-53-54.csv'
OUTPUT_FILE = '/Users/alearuca/.gemini/antigravity/scratch/ffl-funnels-addons/woobooster-rules-import.json'

# Attribute Mapping: CSV Column Name -> Taxonomy Name
TARGET_ATTRIBUTES = {
    'attribute:pa_caliber-gauge': 'pa_caliber-gauge',
    'attribute:pa_manufacturer': 'pa_manufacturer',
    'attribute:pa_brand-fit': 'pa_brand-fit',
    'attribute:pa_model-fit': 'pa_model-fit',
}

def sanitize_title(title):
    """
    Emulate WordPress sanitize_title somewhat.
    Lowercase, replace non-alphanumeric with hyphens, strip leading/trailing hyphens.
    """
    title = title.lower()
    title = title.replace('&amp;', '')
    title = title.replace('&', '')
    title = re.sub(r'[^a-z0-9\-]', '-', title)
    title = re.sub(r'-+', '-', title)
    title = title.strip('-')
    return title

def generate_rules():
    rules = []
    
    unique_values = {
        'pa_caliber-gauge': set(),
        'pa_manufacturer': set(),
        'pa_brand-fit': set(),
        'pa_model-fit': set()
    }

    print(f"Reading CSV: {CSV_FILE}")
    
    try:
        with open(CSV_FILE, 'r', encoding='utf-8') as f:
            reader = csv.DictReader(f)
            
            for row in reader:
                # Process Attributes
                for csv_col, tax_name in TARGET_ATTRIBUTES.items():
                    if csv_col in row and row[csv_col]:
                        raw_val = row[csv_col]
                        vals = [v.strip() for v in raw_val.split(',')]
                        for v in vals:
                            if v:
                                unique_values[tax_name].add(v)

    except Exception as e:
        print(f"Error reading CSV: {e}")
        return

    # 1. Generate Manufacturer Rules (Brand Match)
    # Priority: 20
    for val in unique_values['pa_manufacturer']:
        slug = sanitize_title(val)
        if not slug: continue
        
        rules.append({
            "name": f"Brand: {val}",
            "priority": 20,
            "status": 1,
            "conditions": {
                "0": [
                    {
                        "condition_attribute": "pa_manufacturer",
                        "condition_value": slug,
                        "include_children": 0
                    }
                ]
            },
            "actions": [
                {
                    "action_source": "attribute",
                    "action_value": "pa_manufacturer",
                    "action_limit": 6,
                    "action_orderby": "bestselling"
                }
            ]
        })

    # 2. Generate Caliber Rules (Caliber Match)
    # Priority: 10
    for val in unique_values['pa_caliber-gauge']:
        slug = sanitize_title(val)
        if not slug: continue
        
        rules.append({
            "name": f"Caliber: {val}",
            "priority": 10,
            "status": 1,
            "conditions": {
                "0": [
                    {
                        "condition_attribute": "pa_caliber-gauge",
                        "condition_value": slug,
                        "include_children": 0
                    }
                ]
            },
            "actions": [
                {
                    "action_source": "attribute",
                    "action_value": "pa_caliber-gauge",
                    "action_limit": 6,
                    "action_orderby": "bestselling"
                }
            ]
        })

    # REMOVED: Category Rules (Fallback) as requested by user

    output_data = {
        "version": "1.0.2",
        "date": "2026-02-18",
        "rules": rules
    }
    
    print(f"Generated {len(rules)} rules.")
    
    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(output_data, f, indent=4)
        
    print(f"Saved to {OUTPUT_FILE}")

if __name__ == '__main__':
    generate_rules()
