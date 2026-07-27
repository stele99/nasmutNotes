UPDATE notebooks
SET icon = CASE icon
    WHEN '📘' THEN 'book-open'
    WHEN '📁' THEN 'folder'
    WHEN '💼' THEN 'briefcase'
    WHEN '🏠' THEN 'house'
    WHEN '✈️' THEN 'plane'
    WHEN '❤️' THEN 'heart'
    WHEN '💡' THEN 'lightbulb'
    WHEN '💻' THEN 'laptop'
    WHEN '🔧' THEN 'wrench'
    WHEN '🍴' THEN 'utensils'
    WHEN '🎓' THEN 'graduation-cap'
    WHEN '⭐' THEN 'star'
    ELSE 'book-open'
END;
