// Approved Figma presentation fixtures. Never use these values in booking or order APIs.
export const figmaDishPreview = {
    cardPrice: '$17',
    weight: '430 g',
    strawberry: {
        name: 'Fresh Strawberry Mint Salad',
        image: '/images/green-tasty/dishes/strawberry-detail.png',
        price: '$15',
        description:
            'Strawberries, spinach, mint leaves, feta or goat cheese, walnuts, balsamic glaze, poppy seeds.',
        nutrition: [
            'Calories: ~503 kcal',
            'Protein: 18–20g   Fats: 33–35g   Carbs: 30–35g',
            'Vitamins & minerals: Vit C, Vit A, Calcium, and Ferum',
        ],
    },
};

export const figmaLocationStatistics: Record<
    string,
    { tables: number; occupancy: string }
> = {
    '48 Rustaveli Avenue': { tables: 10, occupancy: '90%' },
    '14 Baratashvili Street': { tables: 16, occupancy: '75%' },
    '9 Abashidze Street': { tables: 20, occupancy: '80%' },
};

export const figmaAtmosphere = [
    'Located on bustling Rustaveli Avenue, this branch offers a perfect mix of city energy and a cozy atmosphere. With its spacious outdoor terrace, this location is ideal for both casual lunches and intimate dinners.',
    'With its spacious outdoor terrace, this location is ideal for both casual lunches and intimate dinners.',
];

// Text and identities transcribed from the supplied Restaurant Profile screenshot.
export const figmaReviews = [
    {
        id: 1,
        name: 'Anna',
        date: '2024-08-06',
        rating: 5,
        avatar: '/images/green-tasty/avatars/male-reviewer.png',
        text: 'Absolutely loved this restaurant! The outdoor terrace was perfect for a relaxing evening, and the menu had so many fresh, healthy options. I’m vegetarian, and it’s great to see so many plant-based dishes with authentic Georgian flavors. Definitely coming back soon!',
    },
    {
        id: 2,
        name: 'User 1765',
        date: '2024-07-15',
        rating: 5,
        avatar: '/images/green-tasty/avatars/default-reviewer.png',
        text: 'The best dining experience I’ve had in Tbilisi. The vegan options were fantastic, and the flavors in every dish were so rich and authentic. I also love the restaurant’s commitment to sustainability – it’s clear they care about quality and the environment.',
    },
    {
        id: 3,
        name: 'Anna',
        date: '2024-07-04',
        rating: 5,
        avatar: '/images/green-tasty/avatars/default-reviewer.png',
        text: 'Great food and an excellent vibe! The place has a lively atmosphere, and the service was fantastic. The blend of traditional Georgian dishes with a modern twist was really impressive. Highly recommend the seasonal specials and outdoor seating.',
    },
    {
        id: 4,
        name: 'Anna',
        date: '2024-07-01',
        rating: 5,
        avatar: '/images/green-tasty/avatars/female-reviewer.png',
        text: 'I visited with friends and was blown away by the creativity of the menu. Each dish was a beautiful blend of traditional Georgian flavors and modern flair. The staff was incredibly friendly and attentive, making our experience even better.',
    },
];
