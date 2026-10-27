// Approved Figma presentation fixtures. Never use these values in booking or order APIs.
export const figmaDishPreview = {
    strawberry: {
        name: 'Fresh Strawberry Mint Salad',
        image: '/images/green-tasty/dishes/strawberry-detail.png',
        description:
            'Strawberries, spinach, mint leaves, feta or goat cheese, walnuts, balsamic glaze, poppy seeds.',
        nutrition: [
            [{ label: 'Calories', value: '~503 kcal' }],
            [
                { label: 'Protein', value: '18–20g' },
                { label: 'Fats', value: '33–35g' },
                { label: 'Carbs', value: '30–35g' },
            ],
            [
                {
                    label: 'Vitamins & minerals',
                    value: 'Vit C, Vit A, Calcium, and Ferum',
                },
            ],
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

export type DemoReviewCategory = 'Service' | 'Cuisine experience';

// Additional fictional examples exercise pagination and both rating sorts.
// They are never persisted or included in a real restaurant rating.
const extraServiceReviews = [
    {
        rating: 4,
        text: 'Our table was ready on time and the team helped us settle in.',
    },
    {
        rating: 3,
        text: 'The welcome was friendly, though ordering took longer than expected.',
    },
    {
        rating: 5,
        text: 'The staff explained the menu carefully and checked on our table.',
    },
    {
        rating: 4,
        text: 'A relaxed lunch with attentive service throughout our visit.',
    },
    { rating: 2, text: 'We had to ask twice for water during a busy evening.' },
    {
        rating: 5,
        text: 'The team made our small group feel welcome from the start.',
    },
    {
        rating: 3,
        text: 'The service was polite, but clearing the table took a while.',
    },
    {
        rating: 4,
        text: 'Booking was straightforward and our server was helpful.',
    },
];
const cuisineReviews = [
    {
        rating: 5,
        text: 'The strawberry salad had a lovely balance of fresh mint, fruit and cheese.',
    },
    {
        rating: 4,
        text: 'The avocado bowl was fresh and the pine nuts added a pleasant crunch.',
    },
    {
        rating: 5,
        text: 'Roasted sweet potato and lentils made a satisfying, flavourful lunch.',
    },
    {
        rating: 4,
        text: 'The spring salad was colourful and the vegetables tasted fresh.',
    },
    {
        rating: 3,
        text: 'The flavours were pleasant, though I would have liked more dressing.',
    },
    {
        rating: 5,
        text: 'The herbs and seasonal vegetables worked beautifully together.',
    },
    {
        rating: 2,
        text: 'The vegetables were softer than I prefer and needed more seasoning.',
    },
    {
        rating: 4,
        text: 'A good selection of light dishes with generous portions.',
    },
    {
        rating: 5,
        text: 'The citrus dessert was a refreshing finish to our meal.',
    },
    {
        rating: 3,
        text: 'The bowl was filling, but the balance of ingredients could be better.',
    },
    {
        rating: 4,
        text: 'The avocado toast had a crisp base and a creamy topping.',
    },
    {
        rating: 5,
        text: 'Fresh ingredients and thoughtful combinations made the meal memorable.',
    },
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

export const demoReviews = [
    ...figmaReviews.map((review) => ({
        ...review,
        category: 'Service' as DemoReviewCategory,
    })),
    ...extraServiceReviews.map((review, index) => ({
        ...review,
        id: index + 5,
        category: 'Service' as DemoReviewCategory,
        name: `Guest ${index + 5}`,
        date: `2024-06-${String(28 - index).padStart(2, '0')}`,
        avatar: '/images/green-tasty/avatars/default-reviewer.png',
    })),
    ...cuisineReviews.map((review, index) => ({
        ...review,
        id: index + 13,
        category: 'Cuisine experience' as DemoReviewCategory,
        name: `Guest ${index + 1}`,
        date: `2024-07-${String(28 - index).padStart(2, '0')}`,
        avatar: '/images/green-tasty/avatars/default-reviewer.png',
    })),
];
